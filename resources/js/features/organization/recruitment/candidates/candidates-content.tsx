import { Link, router, useForm } from '@inertiajs/react';
import { Filter, Plus, TriangleAlert, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import { CandidateFormSheet } from './components/candidate-form-sheet';
import { CandidateKanbanBoard } from './components/candidate-kanban-board';
import { CandidateMovementActions } from './components/candidate-movement-actions';
import { CandidatePrepareOfferDialog } from './components/candidate-prepare-offer-dialog';
import { CandidateReadinessDialog } from './components/candidate-readiness-dialog';
import { CandidateSelectDialog } from './components/candidate-select-dialog';
import {
    CandidateJoiningScheduleBadge,
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from './components/candidate-stage-badge';
import { CandidateStageMoveDialog } from './components/candidate-stage-move-dialog';
import type { StageMoveIntent } from './components/candidate-stage-move-dialog';
import { CandidatesViewToggle } from './components/candidates-view-toggle';
import { emptyCandidateForm } from './lib/candidate-form';
import {
    buildKanbanThroughPageParams,
    candidateKanbanFilterKey,
    mergeKanbanBoard,
    planKanbanBoardSync,
    visibleKanbanStages,
} from './lib/kanban-accumulate';
import { useCandidatesViewPreference } from './lib/use-candidates-view-preference';
import type {
    CandidateFormData,
    CandidateIndexProps,
    CandidateIndexRow,
    CandidateKanbanColumn,
    CandidateStage,
} from './types';
import {
    CANDIDATE_ACTIVE_PIPELINE_STAGES,
    CANDIDATE_STAGE_LABELS,
} from './types';

export function CandidatesContent({
    candidates,
    kanban,
    stage_totals,
    filters,
    search,
    options,
    browse_options,
    can,
    timezone,
}: CandidateIndexProps) {
    const isKanban = filters.view === 'kanban';
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<CandidateIndexRow | null>(null);
    const [duplicateMessage, setDuplicateMessage] = useState<string | null>(
        null,
    );
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [moveIntent, setMoveIntent] = useState<StageMoveIntent | null>(null);
    const [prepareOfferCandidate, setPrepareOfferCandidate] =
        useState<CandidateIndexRow | null>(null);
    const [selectCandidate, setSelectCandidate] =
        useState<CandidateIndexRow | null>(null);
    const [readinessCandidate, setReadinessCandidate] =
        useState<CandidateIndexRow | null>(null);

    const handleRequestMove = (intent: StageMoveIntent) => {
        if (intent.destination.kind === 'prepare_offer') {
            setPrepareOfferCandidate(intent.candidate);

            return;
        }

        setMoveIntent(intent);
    };
    const [pinnedView, pinView] = useCandidatesViewPreference('table');
    const appliedPreferredView = useRef(false);
    const attention = Boolean(filters.attention);
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

    const pipelineStages = visibleKanbanStages(filters.stage);
    const showRejectedLane =
        isKanban &&
        filters.stage !== 'rejected' &&
        (filters.stage === null || filters.stage === undefined);
    // Always request rejected column data for the secondary lane totals/load-more.
    const kanbanSyncStages = useMemo(() => {
        if (!isKanban) {
            return pipelineStages;
        }

        if (filters.stage === 'rejected') {
            return ['rejected'] as CandidateStage[];
        }

        if (filters.stage) {
            return pipelineStages;
        }

        return [...pipelineStages, 'rejected' as CandidateStage];
    }, [filters.stage, isKanban, pipelineStages]);

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
            stages: kanbanSyncStages,
        });

        setAccumulatedKanban(plan.next);
        setPendingAppendStage(null);

        if (plan.needsThroughRefresh) {
            setPendingThroughRefresh(true);
            setThroughRefreshRequest((previous) => ({
                nonce: (previous?.nonce ?? 0) + 1,
                params: buildKanbanThroughPageParams(
                    accumulatedKanban,
                    kanbanSyncStages,
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
            attention: attention ? '1' : null,
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
        attention,
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
            attention: attention ? '1' : '',
        },
        pagination,
    });

    useEffect(() => {
        if (appliedPreferredView.current) {
            return;
        }

        appliedPreferredView.current = true;

        if (typeof window === 'undefined') {
            return;
        }

        const params = new URLSearchParams(window.location.search);

        if (params.has('view')) {
            return;
        }

        if (pinnedView !== filters.view) {
            list.visit({ view: pinnedView, page: null });
        }
        // Apply pinned preference only once when the URL has no explicit view.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- mount-only preference sync
    }, []);

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
        attention: attention ? '1' : '',
    });

    /** Toggle view only — does not write the pinned preference. */
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
            attention: attention ? '1' : null,
            [`page_${stage}`]: page,
        };

        for (const columnStage of kanbanSyncStages) {
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
    const filterRequirements = browse_options.requirements;

    const selectedRequirement = useMemo(
        () =>
            filterRequirements.find(
                (requirement) => requirement.id === filters.requirement_id,
            ) ?? null,
        [filterRequirements, filters.requirement_id],
    );

    const positionOptions = useMemo(() => {
        const lines = selectedRequirement
            ? selectedRequirement.lines
            : filterRequirements.flatMap((requirement) => requirement.lines);

        return Array.from(
            new Map(
                lines.map((line) => [line.position_id, line.position_title]),
            ).entries(),
        );
    }, [filterRequirements, selectedRequirement]);

    const activeFilters = useMemo(() => {
        const chips: Array<{
            key: string;
            label: string;
            clear: Record<string, string>;
        }> = [];

        if (filters.requirement_id && selectedRequirement) {
            chips.push({
                key: 'requirement',
                label: `Requirement: ${selectedRequirement.requirement_number}`,
                clear: {
                    requirement_id: '',
                    requirement_line_id: '',
                    position_id: '',
                },
            });
        }

        if (filters.position_id) {
            const title =
                positionOptions.find(
                    ([id]) => id === filters.position_id,
                )?.[1] ?? `Position #${filters.position_id}`;
            chips.push({
                key: 'position',
                label: `Position: ${title}`,
                clear: { position_id: '' },
            });
        }

        if (filters.stage) {
            chips.push({
                key: 'stage',
                label: `Stage: ${CANDIDATE_STAGE_LABELS[filters.stage as CandidateStage] ?? filters.stage}`,
                clear: { stage: '' },
            });
        }

        if (filters.outcome) {
            const outcomeLabel =
                filters.outcome === 'pending'
                    ? 'Pending'
                    : filters.outcome === 'selected'
                      ? 'Selected'
                      : 'Not Selected';
            chips.push({
                key: 'outcome',
                label: `Interview outcome: ${outcomeLabel}`,
                clear: { outcome: '' },
            });
        }

        if (attention) {
            chips.push({
                key: 'attention',
                label: 'Needs attention',
                clear: { attention: '' },
            });
        }

        return chips;
    }, [filters, selectedRequirement, positionOptions, attention]);

    const clearAllFilters = () => {
        list.applyFilters({
            ...listFiltersForApply(),
            requirement_id: '',
            requirement_line_id: '',
            position_id: '',
            stage: '',
            outcome: '',
            attention: '',
        });
    };

    const onRequirementChange = (value: string) => {
        const nextRequirementId = value === 'all' ? '' : value;
        let nextPositionId = filters.position_id
            ? String(filters.position_id)
            : '';

        if (nextRequirementId) {
            const requirement = filterRequirements.find(
                (item) => String(item.id) === nextRequirementId,
            );
            const allowed = new Set(
                (requirement?.lines ?? []).map((line) => line.position_id),
            );

            if (
                filters.position_id !== null &&
                !allowed.has(filters.position_id)
            ) {
                nextPositionId = '';
            }
        }

        list.applyFilters({
            ...listFiltersForApply(),
            requirement_id: nextRequirementId,
            requirement_line_id: '',
            position_id: nextPositionId,
        });
    };

    const secondaryFilterCount = [filters.outcome, attention ? '1' : ''].filter(
        Boolean,
    ).length;

    return (
        <Main fixed={isKanban} className={cn(isKanban && 'gap-0 p-0')}>
            <TooltipProvider>
                {!isKanban ? (
                    <div className="shrink-0">
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
                    </div>
                ) : null}

                <div
                    className={cn(
                        'shrink-0 space-y-2',
                        isKanban ? 'px-4 py-2' : 'mb-4',
                    )}
                >
                    <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search candidates…"
                            className="mb-0 min-w-0 flex-1"
                        />
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <div className="min-w-[160px] flex-1 sm:w-[180px] sm:flex-none">
                                <AppSelect
                                    value={
                                        filters.requirement_id
                                            ? String(filters.requirement_id)
                                            : 'all'
                                    }
                                    onValueChange={onRequirementChange}
                                    placeholder="Requirement"
                                >
                                    <AppSelectItem value="all">
                                        All requirements
                                    </AppSelectItem>
                                    {filterRequirements.map((requirement) => (
                                        <AppSelectItem
                                            key={requirement.id}
                                            value={String(requirement.id)}
                                        >
                                            {requirement.requirement_number}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>
                            <div className="min-w-[140px] flex-1 sm:w-[160px] sm:flex-none">
                                <AppSelect
                                    value={
                                        filters.position_id
                                            ? String(filters.position_id)
                                            : 'all'
                                    }
                                    onValueChange={(value) =>
                                        list.applyFilters({
                                            ...listFiltersForApply(),
                                            position_id:
                                                value === 'all' ? '' : value,
                                        })
                                    }
                                    placeholder="Position"
                                    disabled={!filters.requirement_id}
                                >
                                    <AppSelectItem value="all">
                                        All positions
                                    </AppSelectItem>
                                    {positionOptions.map(
                                        ([positionId, title]) => (
                                            <AppSelectItem
                                                key={positionId}
                                                value={String(positionId)}
                                            >
                                                {title}
                                            </AppSelectItem>
                                        ),
                                    )}
                                </AppSelect>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant={attention ? 'default' : 'outline'}
                                className="h-9 gap-1.5"
                                onClick={() =>
                                    list.applyFilters({
                                        ...listFiltersForApply(),
                                        attention: attention ? '' : '1',
                                    })
                                }
                                aria-pressed={attention}
                                title="Joining due/overdue, blockers, or pending conversion"
                            >
                                <TriangleAlert className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">
                                    Needs attention
                                </span>
                            </Button>
                            <Popover
                                open={filtersOpen}
                                onOpenChange={setFiltersOpen}
                            >
                                <PopoverTrigger asChild>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-9 gap-1.5"
                                    >
                                        <Filter className="h-3.5 w-3.5" />
                                        Filters
                                        {secondaryFilterCount > 0 ? (
                                            <Badge
                                                variant="secondary"
                                                className="h-5 px-1.5"
                                            >
                                                {secondaryFilterCount}
                                            </Badge>
                                        ) : null}
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="w-72 space-y-3"
                                >
                                    <div className="space-y-1.5">
                                        <p className="text-xs font-medium text-muted-foreground">
                                            Interview outcome
                                        </p>
                                        <AppSelect
                                            value={filters.outcome ?? 'all'}
                                            onValueChange={(value) =>
                                                list.applyFilters({
                                                    ...listFiltersForApply(),
                                                    outcome:
                                                        value === 'all'
                                                            ? ''
                                                            : value,
                                                })
                                            }
                                            placeholder="Interview outcome"
                                        >
                                            <AppSelectItem value="all">
                                                All outcomes
                                            </AppSelectItem>
                                            <AppSelectItem value="pending">
                                                Pending
                                            </AppSelectItem>
                                            <AppSelectItem value="selected">
                                                Selected
                                            </AppSelectItem>
                                            <AppSelectItem value="not_selected">
                                                Not Selected
                                            </AppSelectItem>
                                        </AppSelect>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="w-full"
                                        onClick={() => {
                                            clearAllFilters();
                                            setFiltersOpen(false);
                                        }}
                                    >
                                        Clear all filters
                                    </Button>
                                </PopoverContent>
                            </Popover>
                            <CandidatesViewToggle
                                value={filters.view}
                                pinnedValue={pinnedView}
                                onChange={setView}
                                onPin={pinView}
                            />
                            {can.create ? (
                                <Button
                                    size="sm"
                                    className="h-9"
                                    onClick={openCreate}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onClick={() =>
                                list.applyFilters({
                                    ...listFiltersForApply(),
                                    stage: '',
                                })
                            }
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium transition-colors',
                                !filters.stage
                                    ? 'border-foreground/20 bg-foreground/5 text-foreground'
                                    : 'border-border text-muted-foreground hover:text-foreground',
                            )}
                        >
                            All
                            <span className="text-muted-foreground tabular-nums">
                                {Object.values(stage_totals).reduce(
                                    (a, b) => a + b,
                                    0,
                                )}
                            </span>
                        </button>
                        {CANDIDATE_ACTIVE_PIPELINE_STAGES.map((stage) => (
                            <button
                                key={stage}
                                type="button"
                                onClick={() =>
                                    list.applyFilters({
                                        ...listFiltersForApply(),
                                        stage:
                                            filters.stage === stage
                                                ? ''
                                                : stage,
                                    })
                                }
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium transition-colors',
                                    filters.stage === stage
                                        ? 'border-foreground/25 bg-foreground/5 text-foreground'
                                        : 'border-border text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {CANDIDATE_STAGE_LABELS[stage]}
                                <span className="text-muted-foreground tabular-nums">
                                    {stage_totals[stage] ?? 0}
                                </span>
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() =>
                                list.applyFilters({
                                    ...listFiltersForApply(),
                                    stage:
                                        filters.stage === 'rejected'
                                            ? ''
                                            : 'rejected',
                                })
                            }
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium transition-colors',
                                filters.stage === 'rejected'
                                    ? 'border-rose-500/40 bg-rose-500/10 text-foreground'
                                    : 'border-border text-muted-foreground hover:text-foreground',
                            )}
                        >
                            Rejected
                            <span className="text-muted-foreground tabular-nums">
                                {stage_totals.rejected ?? 0}
                            </span>
                        </button>
                    </div>

                    {activeFilters.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-1.5">
                            {activeFilters.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={() =>
                                        list.applyFilters({
                                            ...listFiltersForApply(),
                                            ...chip.clear,
                                        })
                                    }
                                    className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-[11px] text-foreground hover:bg-muted"
                                >
                                    {chip.label}
                                    <X className="h-3 w-3 text-muted-foreground" />
                                </button>
                            ))}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-6 px-2 text-[11px]"
                                onClick={clearAllFilters}
                            >
                                Clear all
                            </Button>
                        </div>
                    ) : null}
                </div>

                {isKanban && accumulatedKanban ? (
                    <div className="relative flex min-h-0 flex-1 overflow-hidden px-4 pb-3">
                        <CandidateKanbanBoard
                            stages={pipelineStages}
                            rejectedStage={showRejectedLane ? 'rejected' : null}
                            accumulatedKanban={accumulatedKanban}
                            stageTotals={stage_totals}
                            onLoadMore={loadMoreKanban}
                            onRequestMove={handleRequestMove}
                            onRequestSelect={setSelectCandidate}
                            onRequestPrepareOffer={setPrepareOfferCandidate}
                            onRequestReadiness={setReadinessCandidate}
                        />
                    </div>
                ) : !isKanban && rows.length === 0 ? (
                    <div className={cn(isKanban ? 'px-4' : undefined)}>
                        <EmptyState
                            title="No candidates yet"
                            description="Add a candidate to an Open requirement to begin screening."
                            action={
                                can.create ? (
                                    <Button onClick={openCreate}>
                                        Add Candidate
                                    </Button>
                                ) : undefined
                            }
                        />
                    </div>
                ) : !isKanban ? (
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
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {candidate.requirement_number}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {candidate.position_title}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <div className="flex flex-wrap items-center gap-1">
                                                <CandidateStageBadge
                                                    stage={candidate.stage}
                                                    label={
                                                        candidate.stage_label
                                                    }
                                                />
                                                {typeof candidate.days_in_stage ===
                                                'number' ? (
                                                    <span className="text-[11px] text-muted-foreground">
                                                        {
                                                            candidate.days_in_stage
                                                        }
                                                        d
                                                    </span>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
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
                                                    status={
                                                        candidate.offer_status
                                                    }
                                                    label={
                                                        candidate.offer_status_label
                                                    }
                                                />
                                                {candidate.joining_schedule_label ? (
                                                    <CandidateJoiningScheduleBadge
                                                        urgency={
                                                            candidate.joining_schedule_urgency
                                                        }
                                                        label={
                                                            candidate.joining_schedule_label
                                                        }
                                                    />
                                                ) : null}
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <CandidateMovementActions
                                                candidate={candidate}
                                                onRequestMove={
                                                    handleRequestMove
                                                }
                                                onRequestSelect={
                                                    setSelectCandidate
                                                }
                                                onRequestPrepareOffer={
                                                    setPrepareOfferCandidate
                                                }
                                                onRequestReadiness={
                                                    setReadinessCandidate
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                        <Pagination {...list.paginationProps} />
                    </>
                ) : (
                    <div className="px-4 pb-4">
                        <EmptyState
                            title="No candidates in this board"
                            description="Adjust filters or add a candidate to an Open requirement."
                        />
                    </div>
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

                <CandidateStageMoveDialog
                    intent={moveIntent}
                    onClose={() => setMoveIntent(null)}
                    interviewOptions={{
                        interview_modes: options.interview_modes,
                        interviewers: options.interviewers,
                    }}
                />

                <CandidateSelectDialog
                    open={selectCandidate !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setSelectCandidate(null);
                        }
                    }}
                    candidate={selectCandidate}
                />

                <CandidatePrepareOfferDialog
                    open={prepareOfferCandidate !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setPrepareOfferCandidate(null);
                        }
                    }}
                    candidate={prepareOfferCandidate}
                    options={options}
                    timezone={timezone}
                />

                <CandidateReadinessDialog
                    open={readinessCandidate !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setReadinessCandidate(null);
                        }
                    }}
                    candidate={readinessCandidate}
                />
            </TooltipProvider>
        </Main>
    );
}
