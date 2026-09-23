import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import RequirementHoldController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHoldController';
import RequirementOpenController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementOpenController';
import RequirementResumeController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResumeController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import { toast } from '@/lib/toast';
import type {
    RequirementDetail,
    RequirementFilters,
    RequirementIndexProps,
    RequirementIndexRow,
} from '@/types/recruitment';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { RequirementFiltersSheet } from './components/requirement-filters-sheet';
import { RequirementFormSheet } from './components/requirement-form-sheet';
import { RequirementSummaryCards } from './components/requirement-summary-cards';
import { RequirementTable } from './components/requirement-table';
import { RequirementToolbar } from './components/requirement-toolbar';
import { CancelRequirementDialog } from './components/workflow/cancel-requirement-dialog';
import { ChangeHeadcountDialog } from './components/workflow/change-headcount-dialog';
import { ExtendDeadlineDialog } from './components/workflow/extend-deadline-dialog';
import { ReopenRequirementDialog } from './components/workflow/reopen-requirement-dialog';
import { RepeatRequirementDialog } from './components/workflow/repeat-requirement-dialog';
import {
    buildRequirementQuery,
    clearedRequirementFilters,
} from './lib/requirement-filters';

export function RequirementsContent({
    requirements,
    summary,
    tab_counts,
    filters,
    options,
    can,
}: RequirementIndexProps) {
    const [isLoading, setIsLoading] = useState(false);

    // Sheet / dialog open state
    const [isFilterSheetOpen, setIsFilterSheetOpen] = useState(false);
    const [isFormSheetOpen, setIsFormSheetOpen] = useState(false);
    const [editingRequirement, setEditingRequirement] =
        useState<RequirementDetail | null>(null);

    // Workflow dialog targets
    const [extendDialogTarget, setExtendDialogTarget] =
        useState<RequirementIndexRow | null>(null);
    const [changeHeadcountTarget, setChangeHeadcountTarget] =
        useState<RequirementIndexRow | null>(null);
    const [cancelDialogTarget, setCancelDialogTarget] =
        useState<RequirementIndexRow | null>(null);
    const [reopenDialogTarget, setReopenDialogTarget] =
        useState<RequirementIndexRow | null>(null);
    const [repeatDialogTarget, setRepeatDialogTarget] =
        useState<RequirementIndexRow | null>(null);

    const baseUrl = RequirementController.index.url();

    const navigate = useCallback(
        (newFilters: Partial<RequirementFilters>, page?: number) => {
            const query = buildRequirementQuery(filters, newFilters, page);

            router.get(baseUrl, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setIsLoading(true),
                onFinish: () => setIsLoading(false),
            });
        },
        [baseUrl, filters],
    );

    const { searchInput, onSearchChange, resetSearchInput } =
        useDebouncedSearchInput(filters.search || '', (val: string) => {
            navigate({ search: val });
        });

    const handleFilterChange = (changes: Partial<RequirementFilters>) => {
        resetSearchInput(searchInput);
        navigate({ search: searchInput, ...changes });
    };

    const handleSummarySelect = (
        deadlineHealth: 'overdue' | 'due_soon' | null,
    ) => {
        resetSearchInput('');
        navigate({
            ...clearedRequirementFilters,
            search: '',
            tab: 'active',
            deadline_health: deadlineHealth,
        });
    };

    const handleApplyFilters = (draft: RequirementFilters) => {
        handleFilterChange({
            client_id: draft.client_id,
            project_id: draft.project_id,
            position_id: draft.position_id,
            assigned_to: draft.assigned_to,
            priority: draft.priority,
            deadline_health: draft.deadline_health,
        });
    };

    const handleResetFilters = () => {
        handleFilterChange(clearedRequirementFilters);
    };

    const handleClearAll = () => {
        resetSearchInput('');
        navigate({ ...clearedRequirementFilters, search: '' });
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (filters.client_id) {
            count++;
        }

        if (filters.project_id) {
            count++;
        }

        if (filters.position_id) {
            count++;
        }

        if (filters.assigned_to) {
            count++;
        }

        if (filters.priority) {
            count++;
        }

        if (filters.deadline_health) {
            count++;
        }

        return count;
    }, [filters]);

    const hasSearch = Boolean(searchInput);
    const hasActiveFilters = activeFilterCount > 0;

    // Simple workflow button handlers
    const handleOpenRequirement = (row: RequirementIndexRow) => {
        router.post(
            RequirementOpenController.url(row.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`${row.requirement_number} opened.`),
                onError: () => toast.error('Failed to open requirement.'),
            },
        );
    };

    const handleHoldRequirement = (row: RequirementIndexRow) => {
        router.post(
            RequirementHoldController.url(row.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`${row.requirement_number} put on hold.`),
                onError: () =>
                    toast.error('Failed to put requirement on hold.'),
            },
        );
    };

    const handleResumeRequirement = (row: RequirementIndexRow) => {
        router.post(
            RequirementResumeController.url(row.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`${row.requirement_number} resumed.`),
                onError: () => toast.error('Failed to resume requirement.'),
            },
        );
    };

    const handleFillRequirement = (row: RequirementIndexRow) => {
        router.post(
            RequirementFillController.url(row.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        `${row.requirement_number} marked as filled & completed.`,
                    ),
                onError: () =>
                    toast.error('Failed to mark requirement as filled.'),
            },
        );
    };

    const handleEditRequirement = (row: RequirementIndexRow) => {
        router.get(RequirementController.show.url(row.id), { edit: '1' });
    };

    return (
        <Main>
            <RecruitmentBreadcrumbs items={[{ title: 'Requirements' }]} />

            <PageHeader
                kicker="Recruitment"
                title="Requirements"
                description="Manage staffing requests, keep deadlines in sight, and move recruitment forward."
                right={
                    can.create ? (
                        <Button
                            onClick={() => {
                                setEditingRequirement(null);
                                setIsFormSheetOpen(true);
                            }}
                            className="h-10 gap-2 rounded-lg shadow-xs"
                        >
                            <Plus className="h-4 w-4" />
                            Add Requirement
                        </Button>
                    ) : null
                }
            />

            <RequirementSummaryCards
                summary={summary}
                activeCount={tab_counts.active}
                selectedView={
                    filters.tab === 'active' &&
                    !filters.search &&
                    activeFilterCount === (filters.deadline_health ? 1 : 0)
                        ? filters.deadline_health === 'overdue' ||
                          filters.deadline_health === 'due_soon'
                            ? filters.deadline_health
                            : !filters.deadline_health
                              ? 'all'
                              : null
                        : null
                }
                onSelect={handleSummarySelect}
            />

            <div className="mb-4">
                <RequirementToolbar
                    filters={filters}
                    options={options}
                    tab_counts={tab_counts}
                    searchInput={searchInput}
                    total={requirements.total}
                    isLoading={isLoading}
                    onSearchChange={onSearchChange}
                    onClearSearch={() => {
                        resetSearchInput('');
                        navigate({ search: '' });
                    }}
                    onChange={handleFilterChange}
                    onOpenFilters={() => setIsFilterSheetOpen(true)}
                    onReset={handleClearAll}
                />
            </div>

            {/* Requirements Data Table */}
            <div className="space-y-4" aria-busy={isLoading}>
                <RequirementTable
                    rows={requirements.data}
                    activeTab={filters.tab}
                    hasSearch={hasSearch}
                    hasActiveFilters={hasActiveFilters}
                    canCreate={can.create}
                    onAddRequirement={() => {
                        setEditingRequirement(null);
                        setIsFormSheetOpen(true);
                    }}
                    onClearFilters={handleClearAll}
                    onEdit={handleEditRequirement}
                    onOpen={handleOpenRequirement}
                    onHold={handleHoldRequirement}
                    onResume={handleResumeRequirement}
                    onExtend={(row) => setExtendDialogTarget(row)}
                    onChangeHeadcount={(row) => setChangeHeadcountTarget(row)}
                    onFill={handleFillRequirement}
                    onCancel={(row) => setCancelDialogTarget(row)}
                    onReopen={(row) => setReopenDialogTarget(row)}
                    onRepeat={(row) => setRepeatDialogTarget(row)}
                />

                {requirements.total > 0 && (
                    <Pagination
                        currentPage={requirements.current_page}
                        lastPage={requirements.last_page}
                        from={requirements.from ?? 0}
                        to={requirements.to ?? 0}
                        total={requirements.total}
                        perPage={requirements.per_page}
                        label="requirements"
                        perPageOptions={[15, 30, 50, 100]}
                        onPerPageChange={(perPage) =>
                            handleFilterChange({ per_page: perPage })
                        }
                        onPageChange={(page) => navigate({}, page)}
                    />
                )}
            </div>

            {/* Filter Sheet */}
            <RequirementFiltersSheet
                open={isFilterSheetOpen}
                onOpenChange={setIsFilterSheetOpen}
                filters={filters}
                options={options}
                onApply={handleApplyFilters}
                onReset={handleResetFilters}
            />

            {/* Add / Edit Form Sheet */}
            <RequirementFormSheet
                open={isFormSheetOpen}
                onOpenChange={setIsFormSheetOpen}
                initialRequirement={editingRequirement}
                options={options}
            />

            {/* Workflow Action Dialogs */}
            <ExtendDeadlineDialog
                open={Boolean(extendDialogTarget)}
                onOpenChange={(open) => !open && setExtendDialogTarget(null)}
                requirement={extendDialogTarget}
            />

            <ChangeHeadcountDialog
                open={Boolean(changeHeadcountTarget)}
                onOpenChange={(open) => !open && setChangeHeadcountTarget(null)}
                requirement={changeHeadcountTarget}
            />

            <CancelRequirementDialog
                open={Boolean(cancelDialogTarget)}
                onOpenChange={(open) => !open && setCancelDialogTarget(null)}
                requirement={cancelDialogTarget}
            />

            <ReopenRequirementDialog
                open={Boolean(reopenDialogTarget)}
                onOpenChange={(open) => !open && setReopenDialogTarget(null)}
                requirement={reopenDialogTarget}
            />

            <RepeatRequirementDialog
                open={Boolean(repeatDialogTarget)}
                onOpenChange={(open) => !open && setRepeatDialogTarget(null)}
                requirement={repeatDialogTarget}
                recruiters={options.recruiters}
            />
        </Main>
    );
}
