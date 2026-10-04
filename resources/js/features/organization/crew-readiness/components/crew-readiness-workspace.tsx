import { UserCheck } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { MobileRecordList } from '@/components/mobile-record-list';
import { Pagination } from '@/components/pagination';
import { TableBody, TableHeader } from '@/components/ui/table';
import { CrewReadinessDetailSheet } from '@/features/organization/crew-readiness/components/crew-readiness-detail-sheet';
import { CrewReadinessFiltersBar } from '@/features/organization/crew-readiness/components/crew-readiness-filters';
import { CrewReadinessMobileCard } from '@/features/organization/crew-readiness/components/crew-readiness-mobile-card';
import { CrewReadinessSummaryStrip } from '@/features/organization/crew-readiness/components/crew-readiness-summary';
import { CrewReadinessTableRow } from '@/features/organization/crew-readiness/components/crew-readiness-table-row';
import { CREW_READINESS_RESET_QUERY } from '@/features/organization/crew-readiness/lib/crew-readiness-query';
import type {
    CrewReadinessFilters,
    CrewReadinessFocus,
    CrewReadinessPayload,
    CrewReadinessRow,
    OptionItem,
} from '@/features/organization/crew-readiness/types';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import {
    DESKTOP_OPERATIONAL_TABLE_CLASS,
    MOBILE_OPERATIONAL_LIST_CLASS,
} from '@/lib/mobile-operational-list';
import { index as crewReadinessIndex } from '@/routes/organization/crew-readiness';

export function CrewReadinessWorkspace({
    readiness,
    vessels,
    positions,
}: {
    readiness: CrewReadinessPayload;
    vessels: OptionItem[];
    positions: OptionItem[];
}) {
    const [selectedRow, setSelectedRow] = useState<CrewReadinessRow | null>(
        null,
    );

    const {
        searchInput,
        onSearchChange,
        applyFilters,
        visit,
        goToPage,
        setPerPage,
    } = useServerPaginationFilters({
        url: crewReadinessIndex.url(),
        search: readiness.filters.search ?? '',
        filters: {
            vessel_id: readiness.filters.vessel_id,
            position_id: readiness.filters.position_id,
            readiness_status: readiness.filters.readiness_status,
            source: readiness.filters.source,
            window: readiness.filters.window,
            focus: readiness.filters.focus,
        },
        pagination: readiness.pagination,
        only: ['readiness', 'vessels', 'positions'],
    });

    const applyReadinessFilters = (
        next: Partial<CrewReadinessFilters>,
    ): void => {
        applyFilters({
            vessel_id: readiness.filters.vessel_id,
            position_id: readiness.filters.position_id,
            readiness_status: readiness.filters.readiness_status,
            source: readiness.filters.source,
            window: readiness.filters.window,
            focus: readiness.filters.focus,
            ...next,
        });
    };

    const resetFilters = (): void => {
        visit({ ...CREW_READINESS_RESET_QUERY });
    };

    return (
        <div className="space-y-4 px-4 py-4">
            <CrewReadinessSummaryStrip
                summary={readiness.summary}
                activeFocus={readiness.filters.focus}
                onSelect={(focus: CrewReadinessFocus) =>
                    applyReadinessFilters({ focus })
                }
            />

            <div className="mt-4">
                <CrewReadinessFiltersBar
                    searchInput={searchInput}
                    onSearchChange={onSearchChange}
                    filters={readiness.filters}
                    vessels={vessels}
                    positions={positions}
                    filterOptions={readiness.filter_options}
                    hasActiveQuery={readiness.has_active_query}
                    onFilterChange={applyReadinessFilters}
                    onReset={resetFilters}
                />
            </div>

            {readiness.rows.length === 0 ? (
                <EmptyState
                    title="No upcoming crew found"
                    description={
                        readiness.has_active_query
                            ? 'No crew match your current filters. Try changing or clearing your search criteria.'
                            : 'There are no upcoming crew scheduled to mobilise within the current window.'
                    }
                    icon={
                        <UserCheck className="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
                    }
                />
            ) : (
                <>
                    <div className={DESKTOP_OPERATIONAL_TABLE_CLASS}>
                        <OrganizationDataTable>
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Crew</DataTableHead>
                                    <DataTableHead>Rank</DataTableHead>
                                    <DataTableHead>Vessel</DataTableHead>
                                    <DataTableHead>
                                        Source / Stage
                                    </DataTableHead>
                                    <DataTableHead>Arrival</DataTableHead>
                                    <DataTableHead>Expected Join</DataTableHead>
                                    <DataTableHead>Sign-Off</DataTableHead>
                                    <DataTableHead>Readiness</DataTableHead>
                                    <DataTableHead>
                                        Outstanding Items
                                    </DataTableHead>
                                    <DataTableHead className="text-right">
                                        Action
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {readiness.rows.map((row) => (
                                    <CrewReadinessTableRow
                                        key={row.id}
                                        row={row}
                                        onSelect={(r) => setSelectedRow(r)}
                                    />
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>

                    <MobileRecordList className={MOBILE_OPERATIONAL_LIST_CLASS}>
                        {readiness.rows.map((row) => (
                            <CrewReadinessMobileCard
                                key={row.id}
                                row={row}
                                onSelect={(r) => setSelectedRow(r)}
                            />
                        ))}
                    </MobileRecordList>

                    <div className="pt-2">
                        <Pagination
                            currentPage={readiness.pagination.current_page}
                            lastPage={readiness.pagination.last_page}
                            total={readiness.pagination.total}
                            from={readiness.pagination.from}
                            to={readiness.pagination.to}
                            perPage={readiness.pagination.per_page}
                            onPageChange={goToPage}
                            onPerPageChange={setPerPage}
                        />
                    </div>
                </>
            )}

            <CrewReadinessDetailSheet
                row={selectedRow}
                open={Boolean(selectedRow)}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedRow(null);
                    }
                }}
            />
        </div>
    );
}
