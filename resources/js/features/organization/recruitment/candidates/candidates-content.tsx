import { Link, router, useForm } from '@inertiajs/react';
import {
    FileText,
    Kanban,
    LayoutList,
    Mail,
    Phone,
    Plus,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { CandidateFormSheet } from './components/candidate-form-sheet';
import { CandidateMovementActions } from './components/candidate-movement-actions';
import {
    CandidateJoiningScheduleBadge,
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from './components/candidate-stage-badge';
import { emptyCandidateForm } from './lib/candidate-form';
import {
    buildKanbanThroughPageParams,
    candidateKanbanFilterKey,
    mergeKanbanBoard,
    planKanbanBoardSync,
    visibleKanbanStages,
} from './lib/kanban-accumulate';
import type {
    CandidateFormData,
    CandidateIndexProps,
    CandidateIndexRow,
    CandidateKanbanColumn,
    CandidateStage,
} from './types';
import { CANDIDATE_KANBAN_STAGES, CANDIDATE_STAGE_LABELS } from './types';

/** Per-stage accent palette for column top borders, count badges, and card left borders. */
const STAGE_COLORS: Record<
    CandidateStage,
    { border: string; badge: string; header: string }
> = {
    applied: {
        border: 'border-l-slate-400',
        badge: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        header: 'border-t-slate-400',
    },
    screening: {
        border: 'border-l-blue-500',
        badge: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        header: 'border-t-blue-500',
    },
    interview: {
        border: 'border-l-violet-500',
        badge: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
        header: 'border-t-violet-500',
    },
    offer_jol: {
        border: 'border-l-amber-500',
        badge: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        header: 'border-t-amber-500',
    },
    joining: {
        border: 'border-l-orange-500',
        badge: 'bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
        header: 'border-t-orange-500',
    },
    joined: {
        border: 'border-l-emerald-500',
        badge: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        header: 'border-t-emerald-500',
    },
    rejected: {
        border: 'border-l-red-400',
        badge: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
        header: 'border-t-red-400',
    },
};

/** Derive two-letter initials from a candidate name. */
function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function CandidatesContent({
    candidates,
    kanban,
    stage_totals,
    filters,
    search,
    options,
    browse_options,
    can,
}: CandidateIndexProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<CandidateIndexRow | null>(null);
    const [duplicateMessage, setDuplicateMessage] = useState<string | null>(
        null,
    );
    const filterKey = candidateKanbanFilterKey(filters, search);
    const [kanbanFilterKey, setKanbanFilterKey] = useState(filterKey);
    const [pendingAppendStage, setPendingAppendStage] =
        useState<CandidateStage | null>(null);
    const [pendingThroughRefresh, setPendingThroughRefresh] = useState(false);
    const [throughRefreshRequest, setThroughRefreshRequest] = useState<{
        nonce: number;
        params: Record<string, number>;
    } | null>(null);
    const [accumulatedKanban, setAccumulatedKanban] = useState<Record<
        string,
        CandidateKanbanColumn
    > | null>(() => mergeKanbanBoard(null, kanban, null));
    const [syncedKanban, setSyncedKanban] = useState(kanban);

    const kanbanStages = visibleKanbanStages(filters.stage);

    // Adjust accumulated pages when filters change or Inertia returns a fresh board.
    if (filterKey !== kanbanFilterKey) {
        setKanbanFilterKey(filterKey);
        setSyncedKanban(kanban);
        setPendingAppendStage(null);
        setPendingThroughRefresh(false);
        setThroughRefreshRequest(null);
        setAccumulatedKanban(mergeKanbanBoard(null, kanban, null));
    } else if (kanban !== syncedKanban) {
        setSyncedKanban(kanban);

        const plan = planKanbanBoardSync({
            previous: accumulatedKanban,
            incoming: kanban,
            appendStage: pendingAppendStage,
            isThroughRefresh: pendingThroughRefresh,
            stages: kanbanStages,
        });

        setAccumulatedKanban(plan.next);
        setPendingAppendStage(null);

        if (plan.needsThroughRefresh) {
            setPendingThroughRefresh(true);
            setThroughRefreshRequest((previous) => ({
                nonce: (previous?.nonce ?? 0) + 1,
                params: buildKanbanThroughPageParams(
                    accumulatedKanban,
                    kanbanStages,
                ),
            }));
        } else {
            setPendingThroughRefresh(false);
            setThroughRefreshRequest(null);
        }
    }

    useEffect(() => {
        if (!throughRefreshRequest) {
            return;
        }

        if (Object.keys(throughRefreshRequest.params).length === 0) {
            return;
        }

        const params: Record<string, string | number | null> = {
            view: 'kanban',
            search,
            per_page: filters.per_page,
            requirement_id: filters.requirement_id,
            requirement_line_id: filters.requirement_line_id,
            position_id: filters.position_id,
            stage: filters.stage,
            outcome: filters.outcome,
            ...throughRefreshRequest.params,
        };

        router.get('/organization/recruitment/candidates', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['kanban', 'stage_totals', 'filters'],
        });
    }, [
        throughRefreshRequest,
        search,
        filters.per_page,
        filters.requirement_id,
        filters.requirement_line_id,
        filters.position_id,
        filters.stage,
        filters.outcome,
    ]);

    const pagination = candidates
        ? {
              current_page: candidates.current_page,
              last_page: candidates.last_page,
              per_page: candidates.per_page,
              total: candidates.total,
              from: candidates.from,
              to: candidates.to,
          }
        : {
              current_page: 1,
              last_page: 1,
              per_page: filters.per_page,
              total: Object.values(stage_totals).reduce((a, b) => a + b, 0),
              from: null,
              to: null,
          };

    const list = useServerPaginationFilters({
        url: '/organization/recruitment/candidates',
        search,
        filters: {
            view: filters.view,
            requirement_id: filters.requirement_id
                ? String(filters.requirement_id)
                : '',
            position_id: filters.position_id ? String(filters.position_id) : '',
            stage: filters.stage ?? '',
            outcome: filters.outcome ?? '',
            requirement_line_id: filters.requirement_line_id
                ? String(filters.requirement_line_id)
                : '',
        },
        pagination,
    });

    const form = useForm<CandidateFormData>(emptyCandidateForm());

    const openCreate = () => {
        setEditing(null);
        setDuplicateMessage(null);
        form.setData(
            emptyCandidateForm({
                recruitment_requirement_id: filters.requirement_id
                    ? String(filters.requirement_id)
                    : '',
                recruitment_requirement_line_id: filters.requirement_line_id
                    ? String(filters.requirement_line_id)
                    : '',
            }),
        );
        form.clearErrors();
        setSheetOpen(true);
    };

    const submitForm = (forceDuplicate = false) => {
        const onError = (errors: Record<string, string>) => {
            if (errors.duplicate_warning) {
                setDuplicateMessage(errors.duplicate_warning);
            }
        };

        form.transform((data) => ({
            ...data,
            ignore_duplicate_warning: forceDuplicate,
            nationality_id: data.nationality_id || null,
            source: data.source || null,
            recruitment_requirement_id: data.recruitment_requirement_id || null,
            recruitment_requirement_line_id:
                data.recruitment_requirement_line_id || null,
            ...(editing ? { _method: 'put' } : {}),
        }));

        form.post(
            editing
                ? `/organization/recruitment/candidates/${editing.id}`
                : '/organization/recruitment/candidates',
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSheetOpen(false);
                    setDuplicateMessage(null);
                    form.transform((data) => data);
                },
                onError,
                onFinish: () => form.transform((data) => data),
            },
        );
    };

    const listFiltersForApply = () => ({
        view: filters.view,
        requirement_id: filters.requirement_id
            ? String(filters.requirement_id)
            : '',
        position_id: filters.position_id ? String(filters.position_id) : '',
        stage: filters.stage ?? '',
        outcome: filters.outcome ?? '',
        requirement_line_id: filters.requirement_line_id
            ? String(filters.requirement_line_id)
            : '',
    });

    const setView = (view: 'table' | 'kanban') => {
        setPendingAppendStage(null);
        setPendingThroughRefresh(false);
        setThroughRefreshRequest(null);
        list.visit({ view, page: null });
    };

    const loadMoreKanban = (stage: CandidateStage, page: number) => {
        setPendingAppendStage(stage);
        const params: Record<string, string | number | null> = {
            view: 'kanban',
            search,
            per_page: filters.per_page,
            requirement_id: filters.requirement_id,
            requirement_line_id: filters.requirement_line_id,
            position_id: filters.position_id,
            stage: filters.stage,
            outcome: filters.outcome,
            [`page_${stage}`]: page,
        };

        // Preserve other column page cursors so the server does not reset them.
        for (const columnStage of visibleKanbanStages(filters.stage)) {
            if (columnStage === stage) {
                continue;
            }

            const column = accumulatedKanban?.[columnStage];

            if (column && column.current_page > 1) {
                params[`page_${columnStage}`] = column.current_page;
            }
        }

        router.get('/organization/recruitment/candidates', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['kanban', 'stage_totals', 'filters'],
        });
    };

    const rows = candidates?.data ?? [];
    const activeFilterCount = useMemo(
        () =>
            [
                filters.requirement_id,
                filters.position_id,
                filters.stage,
                filters.outcome,
            ].filter(Boolean).length,
        [filters],
    );

    const filterRequirements = browse_options.requirements;

    return (
        <Main>
            <RecruitmentBreadcrumbs
                items={[
                    {
                        title: 'Candidates',
                        href: '/organization/recruitment/candidates',
                    },
                ]}
            />
            <PageHeader
                title="Candidates"
                description="Screen and interview applicants linked to open requirements."
                right={
                    can.create ? (
                        <Button onClick={openCreate}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Candidate
                        </Button>
                    ) : null
                }
            />

            {/* Search + view toggle */}
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder="Search name, contact, requirement..."
                    className="mb-0 flex-1"
                />
                <div className="flex items-center gap-2">
                    {/* Segmented view toggle */}
                    <div className="flex items-center overflow-hidden rounded-md border border-border">
                        <button
                            type="button"
                            className={cn(
                                'flex items-center gap-1.5 px-3 py-1.5 text-sm transition-colors',
                                filters.view === 'table'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-background text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                            onClick={() => setView('table')}
                            aria-label="Table view"
                        >
                            <LayoutList className="h-3.5 w-3.5" />
                            <span className="hidden sm:inline">Table</span>
                        </button>
                        <button
                            type="button"
                            className={cn(
                                'flex items-center gap-1.5 border-l border-border px-3 py-1.5 text-sm transition-colors',
                                filters.view === 'kanban'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-background text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                            onClick={() => setView('kanban')}
                            aria-label="Kanban view"
                        >
                            <Kanban className="h-3.5 w-3.5" />
                            <span className="hidden sm:inline">Kanban</span>
                        </button>
                    </div>
                    {activeFilterCount > 0 ? (
                        <Badge variant="secondary">
                            {activeFilterCount} filter
                            {activeFilterCount !== 1 ? 's' : ''}
                        </Badge>
                    ) : null}
                </div>
            </div>

            {/* Filters row */}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <AppSelect
                    value={
                        filters.requirement_id
                            ? String(filters.requirement_id)
                            : 'all'
                    }
                    onValueChange={(value) =>
                        list.applyFilters({
                            ...listFiltersForApply(),
                            requirement_id: value === 'all' ? '' : value,
                            requirement_line_id: '',
                        })
                    }
                    placeholder="Requirement"
                >
                    <AppSelectItem value="all">All requirements</AppSelectItem>
                    {filterRequirements.map((requirement) => (
                        <AppSelectItem
                            key={requirement.id}
                            value={String(requirement.id)}
                        >
                            {requirement.requirement_number}
                            {requirement.status_label
                                ? ` (${requirement.status_label})`
                                : ''}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                <AppSelect
                    value={
                        filters.position_id
                            ? String(filters.position_id)
                            : 'all'
                    }
                    onValueChange={(value) =>
                        list.applyFilters({
                            ...listFiltersForApply(),
                            position_id: value === 'all' ? '' : value,
                        })
                    }
                    placeholder="Position"
                >
                    <AppSelectItem value="all">All positions</AppSelectItem>
                    {Array.from(
                        new Map(
                            filterRequirements
                                .flatMap((requirement) => requirement.lines)
                                .map((line) => [
                                    line.position_id,
                                    line.position_title,
                                ]),
                        ).entries(),
                    ).map(([positionId, title]) => (
                        <AppSelectItem
                            key={positionId}
                            value={String(positionId)}
                        >
                            {title}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                <AppSelect
                    value={filters.stage ?? 'all'}
                    onValueChange={(value) =>
                        list.applyFilters({
                            ...listFiltersForApply(),
                            stage: value === 'all' ? '' : value,
                        })
                    }
                    placeholder="Stage"
                >
                    <AppSelectItem value="all">All stages</AppSelectItem>
                    {CANDIDATE_KANBAN_STAGES.map((stage) => (
                        <AppSelectItem key={stage} value={stage}>
                            {CANDIDATE_STAGE_LABELS[stage]}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                <AppSelect
                    value={filters.outcome ?? 'all'}
                    onValueChange={(value) =>
                        list.applyFilters({
                            ...listFiltersForApply(),
                            outcome: value === 'all' ? '' : value,
                        })
                    }
                    placeholder="Outcome"
                >
                    <AppSelectItem value="all">All outcomes</AppSelectItem>
                    <AppSelectItem value="pending">Pending</AppSelectItem>
                    <AppSelectItem value="selected">Selected</AppSelectItem>
                    <AppSelectItem value="not_selected">
                        Not Selected
                    </AppSelectItem>
                </AppSelect>
            </div>

            {/* Stage summary pills — shown in table view as clickable stage filters */}
            {filters.view === 'table' && (
                <div className="mb-4 flex flex-wrap gap-2">
                    {CANDIDATE_KANBAN_STAGES.map((stage) => (
                        <button
                            key={stage}
                            type="button"
                            onClick={() =>
                                list.applyFilters({
                                    ...listFiltersForApply(),
                                    stage: filters.stage === stage ? '' : stage,
                                })
                            }
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                filters.stage === stage
                                    ? cn(
                                          STAGE_COLORS[stage].badge,
                                          'border-transparent ring-1 ring-current/40',
                                      )
                                    : 'border-border bg-transparent text-muted-foreground hover:border-foreground/30 hover:text-foreground',
                            )}
                        >
                            {CANDIDATE_STAGE_LABELS[stage]}
                            <span className="tabular-nums">
                                {stage_totals[stage] ?? 0}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {/* Kanban board */}
            {filters.view === 'kanban' && accumulatedKanban ? (
                <TooltipProvider>
                    <div
                        className={cn(
                            'grid gap-3',
                            kanbanStages.length === 1
                                ? 'lg:grid-cols-1'
                                : kanbanStages.length <= 4
                                  ? 'lg:grid-cols-4'
                                  : 'lg:grid-cols-3 xl:grid-cols-6',
                        )}
                    >
                        {kanbanStages.map((stage) => {
                            const column = accumulatedKanban[stage];
                            const stageColor = STAGE_COLORS[stage];
                            const total =
                                stage_totals[stage] ?? column?.total ?? 0;
                            const cards = column?.data ?? [];

                            return (
                                <div
                                    key={stage}
                                    className={cn(
                                        'flex flex-col rounded-xl border border-t-2 border-border/60 bg-muted/30 dark:bg-muted/10',
                                        stageColor.header,
                                    )}
                                >
                                    {/* Column header */}
                                    <div className="flex items-center justify-between px-3 pt-3 pb-2">
                                        <h3 className="text-sm font-semibold tracking-tight">
                                            {CANDIDATE_STAGE_LABELS[stage]}
                                        </h3>
                                        <span
                                            className={cn(
                                                'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                                stageColor.badge,
                                            )}
                                        >
                                            {total}
                                        </span>
                                    </div>

                                    {/* Scrollable cards */}
                                    <div
                                        className="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
                                        style={{ maxHeight: '70vh' }}
                                    >
                                        {cards.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border/50 py-8 text-center">
                                                <Users className="h-6 w-6 text-muted-foreground/40" />
                                                <p className="text-xs text-muted-foreground">
                                                    No candidates
                                                </p>
                                            </div>
                                        ) : null}

                                        {cards.map((candidate) => (
                                            <KanbanCard
                                                key={candidate.id}
                                                candidate={candidate}
                                                stage={stage}
                                                stageColor={stageColor}
                                            />
                                        ))}

                                        {column &&
                                        column.current_page <
                                            column.last_page ? (
                                            <Button
                                                className="mt-1 w-full text-xs"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    loadMoreKanban(
                                                        stage,
                                                        column.current_page + 1,
                                                    )
                                                }
                                            >
                                                Load{' '}
                                                {column.total - cards.length}{' '}
                                                more...
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </TooltipProvider>
            ) : rows.length === 0 ? (
                <EmptyState
                    title="No candidates yet"
                    description="Add a candidate to an Open requirement to begin screening."
                    action={
                        can.create ? (
                            <Button onClick={openCreate}>Add Candidate</Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[980px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Candidate</DataTableHead>
                                <DataTableHead>Requirement</DataTableHead>
                                <DataTableHead>Position</DataTableHead>
                                <DataTableHead>Stage</DataTableHead>
                                <DataTableHead>Outcome</DataTableHead>
                                <DataTableHead>Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((candidate) => (
                                <TableRow
                                    key={candidate.id}
                                    className={dataTableBodyRowClass()}
                                >
                                    <TableCell
                                        className={dataTableCellPrimaryClass()}
                                    >
                                        <Link
                                            href={`/organization/recruitment/candidates/${candidate.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {candidate.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {candidate.email ||
                                                candidate.phone ||
                                                '\u2014'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {candidate.requirement_number}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {candidate.position_title}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <CandidateStageBadge
                                            stage={candidate.stage}
                                            label={candidate.stage_label}
                                        />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <CandidateOutcomeBadge
                                                outcome={
                                                    candidate.interview_outcome
                                                }
                                                label={
                                                    candidate.interview_outcome_label
                                                }
                                            />
                                            <CandidateOfferStatusBadge
                                                status={candidate.offer_status}
                                                label={
                                                    candidate.offer_status_label
                                                }
                                            />
                                            {candidate.stage === 'joining' ? (
                                                <CandidateJoiningScheduleBadge
                                                    urgency={
                                                        candidate.joining_schedule_urgency
                                                    }
                                                    label={
                                                        candidate.joining_schedule_label
                                                    }
                                                />
                                            ) : null}
                                            {candidate.stage === 'joined' &&
                                            candidate.actual_joining_date ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-emerald-600/30 text-emerald-600 dark:text-emerald-400"
                                                >
                                                    Joined:{' '}
                                                    {
                                                        candidate.actual_joining_date
                                                    }
                                                </Badge>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <CandidateMovementActions
                                            candidate={candidate}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>
                    <Pagination {...list.paginationProps} />
                </>
            )}

            <CandidateFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                form={form}
                options={options}
                editing={editing}
                lockedRequirementId={filters.requirement_id}
                lockedLineId={filters.requirement_line_id}
                onSubmit={submitForm}
                duplicateMessage={duplicateMessage}
            />
        </Main>
    );
}

/** A rich kanban card for a single candidate. */
function KanbanCard({
    candidate,
    stage,
    stageColor,
}: {
    candidate: CandidateIndexRow;
    stage: CandidateStage;
    stageColor: { border: string; badge: string; header: string };
}) {
    const initials = getInitials(candidate.name);

    return (
        <div
            className={cn(
                'group relative flex flex-col gap-2 rounded-lg border border-l-2 border-border/60 bg-background p-3 shadow-xs transition-shadow hover:border-border hover:shadow-sm',
                stageColor.border,
            )}
        >
            {/* Top row: avatar + name + CV indicator */}
            <div className="flex items-start gap-2">
                <Avatar className="mt-0.5 size-7 shrink-0">
                    <AvatarFallback className="text-[10px] font-semibold">
                        {initials}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <Link
                        href={`/organization/recruitment/candidates/${candidate.id}`}
                        className="block truncate text-sm leading-tight font-semibold text-foreground hover:text-primary hover:underline"
                    >
                        {candidate.name}
                    </Link>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {candidate.position_title}
                    </p>
                </div>
                {candidate.has_cv ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="mt-0.5 shrink-0 cursor-default text-muted-foreground/50 hover:text-muted-foreground">
                                <FileText className="h-3.5 w-3.5" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>CV attached</TooltipContent>
                    </Tooltip>
                ) : null}
            </div>

            {/* Requirement + source */}
            <p className="truncate text-xs text-muted-foreground">
                <span className="font-medium text-foreground/70">
                    {candidate.requirement_number}
                </span>
                {candidate.source_label ? (
                    <span className="ml-1 text-muted-foreground/60">
                        · {candidate.source_label}
                    </span>
                ) : null}
            </p>

            {/* Contact info */}
            {candidate.email || candidate.phone ? (
                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    {candidate.email ? (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Mail className="h-3 w-3 shrink-0" />
                            <span className="max-w-[120px] truncate">
                                {candidate.email}
                            </span>
                        </span>
                    ) : null}
                    {candidate.phone ? (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Phone className="h-3 w-3 shrink-0" />
                            {candidate.phone}
                        </span>
                    ) : null}
                </div>
            ) : null}

            {/* Status badges */}
            <div className="flex flex-wrap items-center gap-1">
                <CandidateOutcomeBadge
                    outcome={candidate.interview_outcome}
                    label={candidate.interview_outcome_label}
                />
                <CandidateOfferStatusBadge
                    status={candidate.offer_status}
                    label={candidate.offer_status_label}
                />
                {stage === 'joining' ? (
                    <CandidateJoiningScheduleBadge
                        urgency={candidate.joining_schedule_urgency}
                        label={candidate.joining_schedule_label}
                    />
                ) : null}
                {stage === 'joined' && candidate.actual_joining_date ? (
                    <Badge
                        variant="outline"
                        className="border-emerald-600/30 text-emerald-600 dark:text-emerald-400"
                    >
                        Joined: {candidate.actual_joining_date}
                    </Badge>
                ) : null}
            </div>

            {/* Movement actions separated by a hairline divider */}
            <div className="border-t border-border/40 pt-2">
                <CandidateMovementActions candidate={candidate} />
            </div>
        </div>
    );
}
