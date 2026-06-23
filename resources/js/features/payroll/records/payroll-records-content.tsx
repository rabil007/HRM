import { Link } from '@inertiajs/react';
import { Filter } from 'lucide-react';
import { useState } from 'react';
import { index as recordsIndex } from '@/actions/App/Http/Controllers/Payroll/PayrollRecordController';
import { show as payrollShow } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
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
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { PayrollCategoryBadge } from '../components/payroll-category-badge';
import { PayrollRecordsFiltersSheet } from './components/payroll-records-filters-sheet';
import type { PayrollRecordIndexItem, PayrollRecordsFilters } from './types';
import { formatTimesheetAmount } from '../types';
import type { PaginationMeta } from '@/types/pagination';
import type { PayrollCategoryOption } from '../types';

export function PayrollRecordsContent({
    records,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    payroll_categories,
    status_options,
}: {
    records: PayrollRecordIndexItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollRecordsFilters;
    payroll_categories: PayrollCategoryOption[];
    status_options: Array<{ value: string; label: string }>;
}) {
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);

    const list = useServerPaginationFilters({
        url: recordsIndex.url(),
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });

    const activeFiltersCount = [
        initialFilters.category,
        initialFilters.period_id,
        initialFilters.status,
        initialFilters.date_from,
        initialFilters.date_to,
    ].filter(Boolean).length;

    return (
        <Main>
            <PageHeader
                title="Payroll records"
                description="Company-wide payroll records across all pay periods."
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder="Search employees..."
                    className="min-w-[240px] flex-1"
                />
                <Button
                    variant="outline"
                    className="glass-card h-11 rounded-xl px-4"
                    onClick={() => setIsFiltersOpen(true)}
                >
                    <Filter className="mr-2 h-4 w-4" />
                    Filters
                    {activeFiltersCount > 0 ? (
                        <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-xs font-semibold text-primary">
                            {activeFiltersCount}
                        </span>
                    ) : null}
                </Button>
            </div>

            {records.length === 0 ? (
                <EmptyState
                    title="No payroll records"
                    description="Generate payroll from a pay period to create records."
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[1000px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="pl-5">Employee</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Category</DataTableHead>
                                <DataTableHead>Gross</DataTableHead>
                                <DataTableHead>Net</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {records.map((record) => (
                                <TableRow key={record.id} className={dataTableBodyRowClass(false)}>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div className="font-semibold">{record.employee.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {record.employee.employee_no ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="font-medium">{record.period.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDisplayDate(record.period.start_date)} —{' '}
                                            {formatDisplayDate(record.period.end_date)}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <PayrollCategoryBadge category={record.payroll_category} />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetAmount(record.gross_salary)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <span className="font-semibold">
                                            {formatTimesheetAmount(record.net_salary)}
                                        </span>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <span className="text-sm capitalize">{record.status}</span>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
                                        <Button variant="ghost" size="sm" className="rounded-lg" asChild>
                                            <Link
                                                href={payrollShow.url(record.period.id, {
                                                    query: { tab: 'payroll' },
                                                })}
                                            >
                                                View period
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        perPage={pagination.per_page}
                        total={pagination.total}
                        from={pagination.from}
                        to={pagination.to}
                        onPageChange={list.onPageChange}
                    />
                </>
            )}

            <PayrollRecordsFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                filters={initialFilters}
                payrollCategories={payroll_categories}
                statusOptions={status_options}
                onApply={(next) => {
                    list.applyFilters(next);
                    setIsFiltersOpen(false);
                }}
                onClear={() => {
                    list.visit({
                        category: null,
                        period_id: null,
                        status: null,
                        date_from: null,
                        date_to: null,
                        page: null,
                    });
                    setIsFiltersOpen(false);
                }}
            />
        </Main>
    );
}
