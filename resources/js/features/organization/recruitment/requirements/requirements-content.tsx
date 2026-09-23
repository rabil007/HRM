import { router } from '@inertiajs/react';
import { Filter, Plus, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import RequirementHoldController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHoldController';
import RequirementOpenController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementOpenController';
import RequirementResumeController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResumeController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type {
    RequirementDetail,
    RequirementFilters,
    RequirementIndexProps,
    RequirementIndexRow,
    RequirementTab,
} from '@/types/recruitment';
import { RequirementFiltersSheet } from './components/requirement-filters-sheet';
import { RequirementFormSheet } from './components/requirement-form-sheet';
import { RequirementSummaryCards } from './components/requirement-summary-cards';
import { RequirementTable } from './components/requirement-table';
import { CancelRequirementDialog } from './components/workflow/cancel-requirement-dialog';
import { ChangeHeadcountDialog } from './components/workflow/change-headcount-dialog';
import { ExtendDeadlineDialog } from './components/workflow/extend-deadline-dialog';
import { ReopenRequirementDialog } from './components/workflow/reopen-requirement-dialog';
import { RepeatRequirementDialog } from './components/workflow/repeat-requirement-dialog';

export function RequirementsContent({
    requirements,
    summary,
    tab_counts,
    filters,
    options,
    can,
}: RequirementIndexProps) {
    // Search & Filter State
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
            const query: Record<string, string | number> = {
                tab: newFilters.tab ?? filters.tab,
            };

            const searchVal =
                newFilters.search !== undefined
                    ? newFilters.search
                    : filters.search;

            if (searchVal) {
                query.search = searchVal;
            }

            const clientVal =
                newFilters.client_id !== undefined
                    ? newFilters.client_id
                    : filters.client_id;

            if (clientVal) {
                query.client_id = clientVal;
            }

            const projectVal =
                newFilters.project_id !== undefined
                    ? newFilters.project_id
                    : filters.project_id;

            if (projectVal) {
                query.project_id = projectVal;
            }

            const posVal =
                newFilters.position_id !== undefined
                    ? newFilters.position_id
                    : filters.position_id;

            if (posVal) {
                query.position_id = posVal;
            }

            const assignedVal =
                newFilters.assigned_to !== undefined
                    ? newFilters.assigned_to
                    : filters.assigned_to;

            if (assignedVal) {
                query.assigned_to = assignedVal;
            }

            const priorityVal =
                newFilters.priority !== undefined
                    ? newFilters.priority
                    : filters.priority;

            if (priorityVal) {
                query.priority = priorityVal;
            }

            const deadlineVal =
                newFilters.deadline_health !== undefined
                    ? newFilters.deadline_health
                    : filters.deadline_health;

            if (deadlineVal) {
                query.deadline_health = deadlineVal;
            }

            if (page && page > 1) {
                query.page = page;
            }

            if (filters.per_page) {
                query.per_page = filters.per_page;
            }

            router.get(baseUrl, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
        [baseUrl, filters],
    );

    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        filters.search || '',
        (val: string) => {
            navigate({ search: val });
        },
    );

    const handleTabChange = (newTab: RequirementTab) => {
        if (newTab === filters.tab) {
            return;
        }

        navigate({ tab: newTab });
    };

    const handleApplyFilters = (draft: RequirementFilters) => {
        navigate({
            client_id: draft.client_id,
            project_id: draft.project_id,
            position_id: draft.position_id,
            assigned_to: draft.assigned_to,
            priority: draft.priority,
            deadline_health: draft.deadline_health,
        });
    };

    const handleResetFilters = () => {
        navigate({
            client_id: null,
            project_id: null,
            position_id: null,
            assigned_to: null,
            priority: null,
            deadline_health: null,
        });
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
            <PageHeader
                kicker="Recruitment Module"
                title="Recruitment Requirements"
                description="Monitor client staffing demands, headcount targets, deadlines, and requisition lifecycles."
                right={
                    can.create ? (
                        <Button
                            onClick={() => {
                                setEditingRequirement(null);
                                setIsFormSheetOpen(true);
                            }}
                            className="gap-2 shadow-xs"
                        >
                            <Plus className="h-4 w-4" />
                            New Requirement
                        </Button>
                    ) : null
                }
            />

            {/* Summary Metrics Cards */}
            <div className="mb-8">
                <RequirementSummaryCards
                    summary={summary}
                    activeTab={filters.tab}
                    onSelectTab={handleTabChange}
                />
            </div>

            {/* Tabs & Search Controls Bar */}
            <div className="mb-6 space-y-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    {/* Primary Status Tabs */}
                    <div className="flex items-center rounded-xl glass-card p-1">
                        <Button
                            type="button"
                            variant={
                                filters.tab === 'active' ? 'default' : 'ghost'
                            }
                            size="sm"
                            className={cn(
                                'h-9 gap-2 rounded-lg px-4 text-xs font-semibold transition-all',
                                filters.tab !== 'active' &&
                                    'text-muted-foreground hover:bg-accent',
                            )}
                            onClick={() => handleTabChange('active')}
                        >
                            <span>Active</span>
                            <Badge
                                variant={
                                    filters.tab === 'active'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="px-1.5 py-0 text-[10px] font-bold"
                            >
                                {tab_counts.active}
                            </Badge>
                        </Button>

                        <Button
                            type="button"
                            variant={
                                filters.tab === 'on_hold' ? 'default' : 'ghost'
                            }
                            size="sm"
                            className={cn(
                                'h-9 gap-2 rounded-lg px-4 text-xs font-semibold transition-all',
                                filters.tab !== 'on_hold' &&
                                    'text-muted-foreground hover:bg-accent',
                            )}
                            onClick={() => handleTabChange('on_hold')}
                        >
                            <span>On Hold</span>
                            <Badge
                                variant={
                                    filters.tab === 'on_hold'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="px-1.5 py-0 text-[10px] font-bold"
                            >
                                {tab_counts.on_hold}
                            </Badge>
                        </Button>

                        <Button
                            type="button"
                            variant={
                                filters.tab === 'history' ? 'default' : 'ghost'
                            }
                            size="sm"
                            className={cn(
                                'h-9 gap-2 rounded-lg px-4 text-xs font-semibold transition-all',
                                filters.tab !== 'history' &&
                                    'text-muted-foreground hover:bg-accent',
                            )}
                            onClick={() => handleTabChange('history')}
                        >
                            <span>History</span>
                            <Badge
                                variant={
                                    filters.tab === 'history'
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="px-1.5 py-0 text-[10px] font-bold"
                            >
                                {tab_counts.history}
                            </Badge>
                        </Button>
                    </div>

                    {/* Filter Trigger Button */}
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant={
                                activeFilterCount > 0 ? 'secondary' : 'outline'
                            }
                            size="sm"
                            onClick={() => setIsFilterSheetOpen(true)}
                            className="h-10 gap-2 text-xs"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            <span>Filters</span>
                            {activeFilterCount > 0 && (
                                <Badge
                                    variant="default"
                                    className="px-1.5 py-0 text-[10px]"
                                >
                                    {activeFilterCount}
                                </Badge>
                            )}
                        </Button>
                    </div>
                </div>

                {/* Search Bar */}
                <SearchBar
                    placeholder="Search requirement #, client name, client ref, or project..."
                    value={searchInput}
                    onChange={onSearchChange}
                    className="mb-0"
                />

                {/* Active Filter Chips */}
                {activeFilterCount > 0 && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Active filters:
                        </span>

                        {filters.client_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs"
                            >
                                Client:{' '}
                                {options.clients.find(
                                    (c) =>
                                        String(c.id) ===
                                        String(filters.client_id),
                                )?.name || filters.client_id}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() =>
                                        navigate({ client_id: null })
                                    }
                                />
                            </Badge>
                        )}

                        {filters.project_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs"
                            >
                                Project:{' '}
                                {options.projects.find(
                                    (p) =>
                                        String(p.id) ===
                                        String(filters.project_id),
                                )?.title || filters.project_id}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() =>
                                        navigate({ project_id: null })
                                    }
                                />
                            </Badge>
                        )}

                        {filters.position_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs"
                            >
                                Position:{' '}
                                {options.positions.find(
                                    (p) =>
                                        String(p.id) ===
                                        String(filters.position_id),
                                )?.title || filters.position_id}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() =>
                                        navigate({ position_id: null })
                                    }
                                />
                            </Badge>
                        )}

                        {filters.assigned_to && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs"
                            >
                                Recruiter:{' '}
                                {options.recruiters.find(
                                    (r) =>
                                        String(r.id) ===
                                        String(filters.assigned_to),
                                )?.name || filters.assigned_to}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() =>
                                        navigate({ assigned_to: null })
                                    }
                                />
                            </Badge>
                        )}

                        {filters.priority && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs capitalize"
                            >
                                Priority: {filters.priority}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() => navigate({ priority: null })}
                                />
                            </Badge>
                        )}

                        {filters.deadline_health && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-1 text-xs capitalize"
                            >
                                Health:{' '}
                                {filters.deadline_health.replace('_', ' ')}
                                <X
                                    className="h-3 w-3 cursor-pointer hover:text-foreground"
                                    onClick={() =>
                                        navigate({ deadline_health: null })
                                    }
                                />
                            </Badge>
                        )}

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={handleResetFilters}
                            className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                        >
                            Clear all
                        </Button>
                    </div>
                )}
            </div>

            {/* Requirements Data Table */}
            <div className="space-y-4">
                <RequirementTable
                    rows={requirements.data}
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

                {requirements.last_page > 1 && (
                    <Pagination
                        currentPage={requirements.current_page}
                        lastPage={requirements.last_page}
                        from={requirements.from ?? 0}
                        to={requirements.to ?? 0}
                        total={requirements.total}
                        perPage={requirements.per_page}
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
