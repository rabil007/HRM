import { Link, router, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
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
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { CandidateFormSheet } from './components/candidate-form-sheet';
import { CandidateKanbanBoard } from './components/candidate-kanban-board';
import { CandidateMovementActions } from './components/candidate-movement-actions';
import {
    CandidateJoiningScheduleBadge,
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from './components/candidate-stage-badge';
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
import { CANDIDATE_KANBAN_STAGES, CANDIDATE_STAGE_LABELS } from './types';

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
    const [preferredView, setPreferredView] = useCandidatesViewPreference(
        filters.view,
    );
    const appliedPreferredView = useRef(false);
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

        if (preferredView !== filters.view) {
            list.visit({ view: preferredView, page: null });
        }
        // Apply stored preference only on first land without an explicit view query.
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
    });

    const setView = (view: 'table' | 'kanban') => {
        setPreferredView(view);
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

        return chips;
    }, [filters, selectedRequirement, positionOptions]);

    const clearAllFilters = () => {
        list.applyFilters({
            ...listFiltersForApply(),
            requirement_id: '',
            requirement_line_id: '',
            position_id: '',
            stage: '',
            outcome: '',
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

    return (
        <Main>
            <TooltipProvider>
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

                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <SearchBar
                        value={list.searchInput}
                        onChange={list.onSearchChange}
                        placeholder="Search name, contact, requirement..."
                        className="mb-0 flex-1"
                    />
                    <div className="flex items-center gap-2">
                        <CandidatesViewToggle
                            value={filters.view}
                            onChange={setView}
                            onPin={setPreferredView}
                        />
                        {activeFilters.length > 0 ? (
                            <Badge variant="secondary">
                                {activeFilters.length} filter
                                {activeFilters.length !== 1 ? 's' : ''}
                            </Badge>
                        ) : null}
                    </div>
                </div>

                <div className="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                        placeholder={
                            filters.requirement_id
                                ? 'Position'
                                : 'Position (pick requirement)'
                        }
                        disabled={!filters.requirement_id}
                    >
                        <AppSelectItem value="all">All positions</AppSelectItem>
                        {positionOptions.map(([positionId, title]) => (
                            <AppSelectItem
                                key={positionId}
                                value={String(positionId)}
                            >
                                {title}
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
                        placeholder="Interview outcome"
                    >
                        <AppSelectItem value="all">
                            All interview outcomes
                        </AppSelectItem>
                        <AppSelectItem value="pending">Pending</AppSelectItem>
                        <AppSelectItem value="selected">Selected</AppSelectItem>
                        <AppSelectItem value="not_selected">
                            Not Selected
                        </AppSelectItem>
                    </AppSelect>
                </div>

                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <span className="mr-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Pipeline
                    </span>
                    <button
                        type="button"
                        onClick={() =>
                            list.applyFilters({
                                ...listFiltersForApply(),
                                stage: '',
                            })
                        }
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors',
                            !filters.stage
                                ? 'border-foreground/20 bg-foreground/5 text-foreground'
                                : 'border-border bg-transparent text-muted-foreground hover:border-foreground/30 hover:text-foreground',
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
                                'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors',
                                filters.stage === stage
                                    ? 'border-foreground/25 bg-foreground/5 text-foreground ring-1 ring-foreground/10'
                                    : 'border-border bg-transparent text-muted-foreground hover:border-foreground/30 hover:text-foreground',
                            )}
                        >
                            {CANDIDATE_STAGE_LABELS[stage]}
                            <span className="text-muted-foreground tabular-nums">
                                {stage_totals[stage] ?? 0}
                            </span>
                        </button>
                    ))}
                </div>

                {activeFilters.length > 0 ? (
                    <div className="mb-4 flex flex-wrap items-center gap-2">
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
                                className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2.5 py-1 text-xs text-foreground transition-colors hover:bg-muted"
                            >
                                {chip.label}
                                <X className="h-3 w-3 text-muted-foreground" />
                            </button>
                        ))}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={clearAllFilters}
                        >
                            Clear all
                        </Button>
                    </div>
                ) : null}

                {filters.view === 'kanban' && accumulatedKanban ? (
                    <CandidateKanbanBoard
                        stages={kanbanStages}
                        accumulatedKanban={accumulatedKanban}
                        stageTotals={stage_totals}
                        onLoadMore={loadMoreKanban}
                    />
                ) : rows.length === 0 ? (
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
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
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
            </TooltipProvider>
        </Main>
    );
}
