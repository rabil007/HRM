import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { MobileRecordList } from '@/components/mobile-record-list';
import { Pagination } from '@/components/pagination';
import { TableBody, TableHeader } from '@/components/ui/table';
import { ReliefDeskFiltersBar } from '@/features/organization/crew-planning/components/relief-desk-filters';
import { ReliefDeskMobileCard } from '@/features/organization/crew-planning/components/relief-desk-mobile-card';
import { ReliefDeskSummaryStrip } from '@/features/organization/crew-planning/components/relief-desk-summary';
import { ReliefDeskTableRow } from '@/features/organization/crew-planning/components/relief-desk-table-row';
import { RELIEF_DESK_RESET_QUERY } from '@/features/organization/crew-planning/lib/relief-desk-query';
import type {
    PlanningOption,
    ReliefDeskFocus,
    ReliefDeskPayload,
} from '@/features/organization/crew-planning/types';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import {
    DESKTOP_OPERATIONAL_TABLE_CLASS,
    MOBILE_OPERATIONAL_LIST_CLASS,
} from '@/lib/mobile-operational-list';
import { index as planningIndex } from '@/routes/organization/crew-planning';

export function ReliefDesk({
    desk,
    vessels,
    ranks,
}: {
    desk: ReliefDeskPayload;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
}) {
    const {
        searchInput,
        onSearchChange,
        applyFilters,
        visit,
        goToPage,
        setPerPage,
    } = useServerPaginationFilters({
        url: planningIndex.url(),
        search: desk.filters.search,
        filters: {
            view: 'relief',
            vessel_id: desk.filters.vessel_id,
            rank_id: desk.filters.rank_id,
            client_id: desk.filters.client_id,
            relief_status: desk.filters.relief_status,
            relief_risk: desk.filters.relief_risk,
            planned_signoff_from: desk.filters.planned_signoff_from,
            planned_signoff_to: desk.filters.planned_signoff_to,
            horizon: desk.filters.horizon,
            focus: desk.filters.focus,
        },
        pagination: desk.pagination,
        only: ['view', 'relief_desk', 'filters', 'can', 'vessels', 'ranks'],
    });

    const applyDeskFilters = (
        next: Partial<ReliefDeskPayload['filters']> & {
            search?: string;
            focus?: ReliefDeskFocus;
        },
    ): void => {
        applyFilters({
            view: 'relief',
            vessel_id: desk.filters.vessel_id,
            rank_id: desk.filters.rank_id,
            client_id: desk.filters.client_id,
            relief_status: desk.filters.relief_status,
            relief_risk: desk.filters.relief_risk,
            planned_signoff_from: desk.filters.planned_signoff_from,
            planned_signoff_to: desk.filters.planned_signoff_to,
            horizon: desk.filters.horizon,
            focus: desk.filters.focus,
            ...next,
        });
    };

    const resetFilters = (): void => {
        visit({ ...RELIEF_DESK_RESET_QUERY });
    };

    return (
        <div className="px-4 py-4">
            <ReliefDeskSummaryStrip
                summary={desk.summary}
                activeFocus={desk.filters.focus}
                onSelect={(focus: ReliefDeskFocus) =>
                    applyDeskFilters({ focus })
                }
            />

            <div className="mt-4">
                <ReliefDeskFiltersBar
                    searchInput={searchInput}
                    onSearchChange={onSearchChange}
                    filters={desk.filters}
                    vessels={vessels}
                    ranks={ranks}
                    filterOptions={desk.filter_options}
                    onFilterChange={(next) => applyDeskFilters(next)}
                    onReset={resetFilters}
                />
            </div>

            {desk.rows.length === 0 ? (
                <EmptyState
                    title={
                        desk.has_active_query
                            ? 'No relief cases match these filters.'
                            : 'No upcoming relief actions'
                    }
                    description={
                        desk.has_active_query
                            ? 'Try clearing search or filters to widen the desk.'
                            : 'No onboard crew currently require relief attention in the selected period.'
                    }
                />
            ) : (
                <>
                    <MobileRecordList className={MOBILE_OPERATIONAL_LIST_CLASS}>
                        {desk.rows.map((row) => (
                            <ReliefDeskMobileCard key={row.id} row={row} />
                        ))}
                    </MobileRecordList>

                    <div className={DESKTOP_OPERATIONAL_TABLE_CLASS}>
                        <OrganizationDataTable
                            minWidth="min-w-[1120px]"
                            compact
                        >
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Vessel / Rank</DataTableHead>
                                    <DataTableHead>Current Crew</DataTableHead>
                                    <DataTableHead>
                                        Planned Sign-Off
                                    </DataTableHead>
                                    <DataTableHead>Relief</DataTableHead>
                                    <DataTableHead>Relief Phase</DataTableHead>
                                    <DataTableHead>Readiness</DataTableHead>
                                    <DataTableHead>Risk</DataTableHead>
                                    <DataTableHead>Action</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {desk.rows.map((row) => (
                                    <ReliefDeskTableRow
                                        key={row.id}
                                        row={row}
                                    />
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>

                    <Pagination
                        className="mt-4"
                        currentPage={desk.pagination.current_page}
                        lastPage={desk.pagination.last_page}
                        from={desk.pagination.from}
                        to={desk.pagination.to}
                        total={desk.pagination.total}
                        perPage={desk.pagination.per_page}
                        onPageChange={goToPage}
                        onPerPageChange={setPerPage}
                    />
                </>
            )}
        </div>
    );
}
