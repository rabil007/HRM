import { router } from '@inertiajs/react';
import { Filter, Loader2, Plus, Ship } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import { CrewAssignmentsTableRow } from '@/features/organization/crew/components/crew-assignments-table-row';
import { CrewFiltersSheet } from '@/features/organization/crew/components/crew-filters-sheet';
import { CrewSummaryCards } from '@/features/organization/crew/components/crew-summary-cards';
import type {
    CrewAssignmentFilterOptions,
    CrewAssignmentFilters,
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentSummary,
} from '@/features/organization/crew/types';
import { CREW_PHASE_LABELS } from '@/features/organization/crew/types';
import {
    useCrewIndexFilters,
    type CrewSummaryFilter,
} from '@/features/organization/crew/use-crew-index-filters';
import { cn } from '@/lib/utils';
import {
    create as createAssignment,
    edit as editAssignment,
    index as crewAssignmentsIndex,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import type { PaginationMeta } from '@/types/pagination';

function normalizeFilters(
    filters: Partial<CrewAssignmentFilters> | Record<string, unknown>,
): CrewAssignmentFilters {
    return {
        phase: String(filters.phase ?? ''),
        status: String(filters.status ?? ''),
        vessel_id: String(filters.vessel_id ?? ''),
        rank_id: String(filters.rank_id ?? ''),
        client_id: String(filters.client_id ?? ''),
        employee_id: String(filters.employee_id ?? ''),
        planned_join_from: String(filters.planned_join_from ?? ''),
        planned_join_to: String(filters.planned_join_to ?? ''),
        planned_signoff_from: String(filters.planned_signoff_from ?? ''),
        planned_signoff_to: String(filters.planned_signoff_to ?? ''),
        movement_attention: Boolean(filters.movement_attention),
        include_completed: Boolean(filters.include_completed),
    };
}

function resolveActiveSummaryFilter(
    filters: CrewAssignmentFilters,
): CrewSummaryFilter {
    if (filters.movement_attention) {
        return 'attention';
    }

    if (filters.phase === 'p4') {
        return 'on_vessel';
    }

    if (filters.phase === 'p0') {
        return 'pre_mobilisation';
    }

    return '';
}

export function CurrentCrewContent({
    assignments,
    pagination,
    search: initialSearch,
    filters: rawFilters,
    summary,
    filter_options: filterOptions,
    can,
}: {
    assignments: CrewAssignmentListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: Partial<CrewAssignmentFilters> | Record<string, unknown>;
    summary: CrewAssignmentSummary;
    filter_options: CrewAssignmentFilterOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const filters = useMemo(() => normalizeFilters(rawFilters), [rawFilters]);
    const activeSummaryFilter = resolveActiveSummaryFilter(filters);

    const {
        searchInput,
        isSearching,
        onSearchChange,
        onSummaryFilterChange,
        onPhaseChange,
        onSheetFiltersChange,
        onResetFilters,
        onPageChange,
    } = useCrewIndexFilters({
        url: crewAssignmentsIndex.url(),
        initialSearch,
        initialFilters: filters,
        perPage: pagination.per_page,
    });

    const activeFiltersCount = [
        filters.phase,
        filters.status,
        filters.vessel_id,
        filters.rank_id,
        filters.client_id,
        filters.employee_id,
        filters.planned_join_from,
        filters.planned_join_to,
        filters.planned_signoff_from,
        filters.planned_signoff_to,
        filters.movement_attention ? '1' : '',
        filters.include_completed ? '1' : '',
    ].filter(Boolean).length;

    const hasActiveQuery =
        Boolean(searchInput.trim()) || activeFiltersCount > 0;

    const phaseChips = useMemo(() => {
        return Object.entries(CREW_PHASE_LABELS).map(([code, label]) => ({
            code,
            label,
            count: summary.by_phase[code] ?? 0,
        }));
    }, [summary.by_phase]);

    return (
        <Main>
            <PageHeader
                title="Current Crew"
                description="Track mobilisation, vessel joins, and demobilisation in one operational board."
                right={
                    can.create ? (
                        <Button
                            onClick={() => router.visit(createAssignment.url())}
                        >
                            <Plus className="h-4 w-4" />
                            New Assignment
                        </Button>
                    ) : undefined
                }
            />

            <CrewSummaryCards
                summary={summary}
                activeFilter={activeSummaryFilter}
                onSelect={onSummaryFilterChange}
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {phaseChips.map((chip) => {
                    const isActive = filters.phase === chip.code;

                    return (
                        <button
                            key={chip.code}
                            type="button"
                            onClick={() =>
                                onPhaseChange(isActive ? '' : chip.code)
                            }
                            aria-pressed={isActive}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                                isActive
                                    ? 'border-primary/40 bg-primary/10 text-primary'
                                    : 'border-border/70 bg-muted/20 text-muted-foreground hover:border-border hover:bg-muted/40 hover:text-foreground',
                            )}
                        >
                            <span className="font-semibold uppercase">
                                {chip.code}
                            </span>
                            <span className="hidden sm:inline">
                                {chip.label}
                            </span>
                            <Badge
                                variant="secondary"
                                className="h-5 min-w-5 justify-center rounded-full px-1.5 text-[10px]"
                            >
                                {chip.count}
                            </Badge>
                        </button>
                    );
                })}
            </div>

            <SearchBar
                placeholder="Search assignment no, employee, vessel, rank, or client..."
                value={searchInput}
                onChange={onSearchChange}
                right={
                    <div className="flex items-center gap-3">
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                            onClick={() => setIsFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                            {activeFiltersCount ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {activeFiltersCount}
                                </span>
                            ) : null}
                        </Button>
                    </div>
                }
            />

            {assignments.length === 0 ? (
                <EmptyState
                    icon={
                        <Ship className="mx-auto mb-3 size-8 text-muted-foreground/50" />
                    }
                    title={
                        hasActiveQuery
                            ? 'No matching crew assignments'
                            : 'No active crew assignments'
                    }
                    description={
                        hasActiveQuery
                            ? 'Try clearing search or filters to widen the board.'
                            : 'Create a draft assignment to start mobilisation tracking.'
                    }
                    action={
                        can.create && !hasActiveQuery ? (
                            <Button
                                onClick={() =>
                                    router.visit(createAssignment.url())
                                }
                            >
                                <Plus className="h-4 w-4" />
                                New Assignment
                            </Button>
                        ) : hasActiveQuery ? (
                            <Button variant="outline" onClick={onResetFilters}>
                                Clear filters
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable
                        minWidth="min-w-[1280px]"
                        tableClassName="table-fixed"
                    >
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-[150px]">
                                    Assignment
                                </DataTableHead>
                                <DataTableHead className="w-[220px]">
                                    Employee
                                </DataTableHead>
                                <DataTableHead className="w-[160px]">
                                    Vessel
                                </DataTableHead>
                                <DataTableHead className="w-[120px]">
                                    Rank
                                </DataTableHead>
                                <DataTableHead className="w-[200px]">
                                    Current Phase
                                </DataTableHead>
                                <DataTableHead className="w-[140px]">
                                    Plan Dates
                                </DataTableHead>
                                <DataTableHead className="w-[130px]">
                                    Status
                                </DataTableHead>
                                <DataTableHead className="w-[88px]">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {assignments.map((assignment) => (
                                <CrewAssignmentsTableRow
                                    key={assignment.id}
                                    assignment={assignment}
                                    viewHref={showAssignment.url(assignment.id)}
                                    editHref={
                                        can.update
                                            ? editAssignment.url(assignment.id)
                                            : undefined
                                    }
                                    canUpdate={can.update}
                                />
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    {pagination.last_page > 1 ? (
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            perPage={pagination.per_page}
                            total={pagination.total}
                            from={pagination.from}
                            to={pagination.to}
                            onPageChange={onPageChange}
                        />
                    ) : null}
                </>
            )}

            <CrewFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                filterOptions={filterOptions}
                value={filters}
                onChange={onSheetFiltersChange}
                onReset={onResetFilters}
            />
        </Main>
    );
}
