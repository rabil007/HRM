import { Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
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

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder="Search name, contact, requirement…"
                    className="mb-0 flex-1"
                />
                <div className="flex items-center gap-2">
                    <Button
                        size="sm"
                        variant={
                            filters.view === 'table' ? 'default' : 'outline'
                        }
                        onClick={() => setView('table')}
                    >
                        Table
                    </Button>
                    <Button
                        size="sm"
                        variant={
                            filters.view === 'kanban' ? 'default' : 'outline'
                        }
                        onClick={() => setView('kanban')}
                    >
                        Kanban
                    </Button>
                    {activeFilterCount > 0 ? (
                        <Badge variant="secondary">
                            {activeFilterCount} filters
                        </Badge>
                    ) : null}
                </div>
            </div>

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

            <div className="mb-4 flex flex-wrap gap-2">
                {CANDIDATE_KANBAN_STAGES.map((stage) => (
                    <Badge key={stage} variant="outline">
                        {CANDIDATE_STAGE_LABELS[stage]}:{' '}
                        {stage_totals[stage] ?? 0}
                    </Badge>
                ))}
            </div>

            {filters.view === 'kanban' && accumulatedKanban ? (
                <div
                    className={`grid gap-4 ${kanbanStages.length === 1 ? 'lg:grid-cols-1' : kanbanStages.length <= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3 xl:grid-cols-6'}`}
                >
                    {kanbanStages.map((stage) => {
                        const column = accumulatedKanban[stage];

                        return (
                            <div
                                key={stage}
                                className="rounded-xl border border-border/60 bg-card/40 p-3"
                            >
                                <div className="mb-3 flex items-center justify-between">
                                    <h3 className="text-sm font-semibold">
                                        {CANDIDATE_STAGE_LABELS[stage]}
                                    </h3>
                                    <Badge variant="secondary">
                                        {stage_totals[stage] ??
                                            column?.total ??
                                            0}
                                    </Badge>
                                </div>
                                <div className="space-y-2">
                                    {(column?.data ?? []).map((candidate) => (
                                        <div
                                            key={candidate.id}
                                            className="rounded-lg border border-border/50 bg-background p-3"
                                        >
                                            <Link
                                                href={`/organization/recruitment/candidates/${candidate.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {candidate.name}
                                            </Link>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {candidate.requirement_number} ·{' '}
                                                {candidate.position_title}
                                            </p>
                                            <div className="mt-2 flex flex-wrap items-center gap-1">
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
                                                {candidate.stage ===
                                                'joining' ? (
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
                                            <div className="mt-2">
                                                <CandidateMovementActions
                                                    candidate={candidate}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                    {(column?.data ?? []).length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            No candidates
                                        </p>
                                    ) : null}
                                </div>
                                {column &&
                                column.current_page < column.last_page ? (
                                    <Button
                                        className="mt-3 w-full"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadMoreKanban(
                                                stage,
                                                column.current_page + 1,
                                            )
                                        }
                                    >
                                        Load more
                                    </Button>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
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
                                                '—'}
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
