import { router } from '@inertiajs/react';
import { Filter, Plus, Search, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import RequirementFillController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementFillController';
import RequirementHoldController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementHoldController';
import RequirementOpenController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementOpenController';
import RequirementResumeController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementResumeController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
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
                description="Track client staffing demands, headcount targets, deadlines, and requisition lifecycles."
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
                            Add Requirement
                        </Button>
                    ) : null
                }
            />

            {/* Summary Metrics Cards */}
            <div className="mb-6">
                <RequirementSummaryCards
                    summary={summary}
                    activeTab={filters.tab}
                    activeDeadlineHealth={
                        filters.deadline_health as string | null | undefined
                    }
                    onSelectTab={handleTabChange}
                    onSelectFilter={(deadlineHealth) => {
                        navigate({
                            tab: 'active',
                            deadline_health: deadlineHealth,
                        });
                    }}
                />
            </div>

            {/* Unified control row: Tabs | Search | Filters */}
            <div className="mb-4">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
                    {/* Primary Status Tabs */}
                    <div className="flex shrink-0 items-center rounded-xl border border-border/60 bg-muted/30 p-1 backdrop-blur-sm">
                        {(
                            [
                                {
                                    key: 'active',
                                    label: 'Active',
                                    count: tab_counts.active,
                                },
                                {
                                    key: 'on_hold',
                                    label: 'On Hold',
                                    count: tab_counts.on_hold,
                                },
                                {
                                    key: 'history',
                                    label: 'History',
                                    count: tab_counts.history,
                                },
                            ] as const
                        ).map(({ key, label, count }) => (
                            <Button
                                key={key}
                                type="button"
                                variant={
                                    filters.tab === key ? 'default' : 'ghost'
                                }
                                size="sm"
                                className={cn(
                                    'h-8 gap-2 rounded-lg px-3 text-xs font-semibold transition-all',
                                    filters.tab !== key &&
                                        'text-muted-foreground hover:bg-accent hover:text-foreground',
                                )}
                                onClick={() => handleTabChange(key)}
                            >
                                <span>{label}</span>
                                <Badge
                                    variant={
                                        filters.tab === key
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    className="px-1.5 py-0 text-[10px] font-bold tabular-nums"
                                >
                                    {count}
                                </Badge>
                            </Button>
                        ))}
                    </div>

                    {/* Search — grows to fill available space */}
                    <div className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search requirement #, client, project or position…"
                            value={searchInput}
                            onChange={(e) => onSearchChange(e.target.value)}
                            className="h-9 pr-3 pl-9 text-sm"
                            aria-label="Search requirements"
                        />
                    </div>

                    {/* Filters trigger */}
                    <div className="flex shrink-0 items-center gap-2">
                        <Button
                            type="button"
                            variant={
                                activeFilterCount > 0 ? 'secondary' : 'outline'
                            }
                            size="sm"
                            onClick={() => setIsFilterSheetOpen(true)}
                            className="h-9 gap-2 text-xs"
                            aria-label={
                                activeFilterCount > 0
                                    ? `Filters active (${activeFilterCount})`
                                    : 'Open filters'
                            }
                        >
                            <Filter className="h-3.5 w-3.5" />
                            <span>Filters</span>
                            {activeFilterCount > 0 && (
                                <Badge
                                    variant="default"
                                    className="px-1.5 py-0 text-[10px] tabular-nums"
                                >
                                    {activeFilterCount}
                                </Badge>
                            )}
                        </Button>

                        {hasActiveFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={handleResetFilters}
                                className="h-9 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
                                aria-label="Clear all filters"
                            >
                                <X className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">Clear</span>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Active Filter Chips */}
                {hasActiveFilters && (
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Filters:
                        </span>

                        {filters.client_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs"
                            >
                                Client:{' '}
                                {options.clients.find(
                                    (c) =>
                                        String(c.id) ===
                                        String(filters.client_id),
                                )?.name || filters.client_id}
                                <button
                                    type="button"
                                    onClick={() =>
                                        navigate({ client_id: null })
                                    }
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove client filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}

                        {filters.project_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs"
                            >
                                Project:{' '}
                                {options.projects.find(
                                    (p) =>
                                        String(p.id) ===
                                        String(filters.project_id),
                                )?.title || filters.project_id}
                                <button
                                    type="button"
                                    onClick={() =>
                                        navigate({ project_id: null })
                                    }
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove project filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}

                        {filters.position_id && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs"
                            >
                                Position:{' '}
                                {options.positions.find(
                                    (p) =>
                                        String(p.id) ===
                                        String(filters.position_id),
                                )?.title || filters.position_id}
                                <button
                                    type="button"
                                    onClick={() =>
                                        navigate({ position_id: null })
                                    }
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove position filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}

                        {filters.assigned_to && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs"
                            >
                                Recruiter:{' '}
                                {options.recruiters.find(
                                    (r) =>
                                        String(r.id) ===
                                        String(filters.assigned_to),
                                )?.name || filters.assigned_to}
                                <button
                                    type="button"
                                    onClick={() =>
                                        navigate({ assigned_to: null })
                                    }
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove recruiter filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}

                        {filters.priority && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs capitalize"
                            >
                                Priority: {filters.priority}
                                <button
                                    type="button"
                                    onClick={() => navigate({ priority: null })}
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove priority filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}

                        {filters.deadline_health && (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 py-0.5 text-xs capitalize"
                            >
                                Health:{' '}
                                {filters.deadline_health.replace('_', ' ')}
                                <button
                                    type="button"
                                    onClick={() =>
                                        navigate({ deadline_health: null })
                                    }
                                    className="rounded-full hover:text-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Remove deadline health filter"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}
                    </div>
                )}
            </div>

            {/* Requirements Data Table */}
            <div className="space-y-4">
                <RequirementTable
                    rows={requirements.data}
                    hasSearch={hasSearch}
                    hasActiveFilters={hasActiveFilters}
                    canCreate={can.create}
                    onAddRequirement={() => {
                        setEditingRequirement(null);
                        setIsFormSheetOpen(true);
                    }}
                    onClearFilters={handleResetFilters}
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
