import { router } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
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
import type { PaginationMeta } from '@/types/pagination';
import type {
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentSummary,
} from './types';

export function CurrentCrewContent({
    assignments,
    pagination,
    search: initialSearch,
    summary,
    can,
}: {
    assignments: CrewAssignmentListItem[];
    pagination: PaginationMeta;
    search: string;
    summary: CrewAssignmentSummary;
    can: CrewAssignmentPagePermissions;
}) {
    const list = useServerPaginationFilters({
        url: '/organization/crew',
        search: initialSearch,
        filters: {},
        pagination,
    });

    const [isFiltersOpen, setIsFiltersOpen] = useState(false);

    return (
        <Main>
            <PageHeader
                title="Current Crew"
                description="Manage crew assignments and movements"
                right={
                    can.create ? (
                        <Button onClick={() => router.visit('/organization/crew/create')}>
                            <Plus className="h-4 w-4" />
                            New Assignment
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-6 grid gap-4 md:grid-cols-3">
                <div className="glass-card rounded-xl p-6">
                    <div className="text-2xl font-bold">{summary.total}</div>
                    <div className="text-sm text-muted-foreground">Total Active</div>
                </div>
                <div className="glass-card rounded-xl p-6">
                    <div className="text-2xl font-bold text-amber-600">
                        {summary.needs_attention}
                    </div>
                    <div className="text-sm text-muted-foreground">Needs Attention</div>
                </div>
                <div className="glass-card rounded-xl p-6">
                    <div className="text-sm text-muted-foreground">By Phase</div>
                    <div className="mt-2 flex flex-wrap gap-1">
                        {Object.entries(summary.by_phase).map(([phase, count]) => (
                            <Badge key={phase} variant="outline">
                                {phase}: {count}
                            </Badge>
                        ))}
                    </div>
                </div>
            </div>

            <div className="glass-card rounded-xl">
                <div className="p-4">
                    <SearchBar
                        value={list.searchInput}
                        onChange={(value) => list.onSearchChange(value)}
                        placeholder="Search assignments..."
                        className="flex-1"
                    />
                </div>

                {assignments.length === 0 ? (
                    <EmptyState
                        title="No crew assignments found"
                        description="Get started by creating a new crew assignment"
                    />
                ) : (
                    <>
                        <OrganizationDataTable>
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Assignment</DataTableHead>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead>Vessel</DataTableHead>
                                    <DataTableHead>Rank</DataTableHead>
                                    <DataTableHead>Phase</DataTableHead>
                                    <DataTableHead>Status</DataTableHead>
                                    <DataTableHead>Actions</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {assignments.map((assignment) => (
                                    <TableRow
                                        key={assignment.id}
                                        className={dataTableBodyRowClass()}
                                        onClick={() =>
                                            router.visit(`/organization/crew/${assignment.id}`)
                                        }
                                        style={{ cursor: 'pointer' }}
                                    >
                                        <TableCell className={dataTableCellPrimaryClass()}>
                                            <div className="font-medium">
                                                {assignment.assignment_no}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {assignment.created_at}
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {assignment.employee?.name ?? 'N/A'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {assignment.vessel?.name ?? 'N/A'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {assignment.rank?.name ?? 'N/A'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {assignment.current_phase ? (
                                                <Badge variant="outline">
                                                    {assignment.current_phase.label}
                                                </Badge>
                                            ) : (
                                                'N/A'
                                            )}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <Badge
                                                variant={
                                                    assignment.status === 'active'
                                                        ? 'success'
                                                        : 'secondary'
                                                }
                                            >
                                                {assignment.status_label}
                                            </Badge>
                                            {assignment.warnings.length > 0 && (
                                                <Badge variant="destructive" className="ml-1">
                                                    {assignment.warnings.length}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableActionsCellClass()}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.visit(`/organization/crew/${assignment.id}`)
                                                }
                                            >
                                                View
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </OrganizationDataTable>

                        <Pagination {...list.paginationProps} />
                    </>
                )}
            </div>
        </Main>
    );
}
