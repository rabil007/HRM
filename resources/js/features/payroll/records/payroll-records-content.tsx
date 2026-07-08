import { Link } from '@inertiajs/react';
import { Eye, Filter } from 'lucide-react';
import { useState } from 'react';
import { show as payrollShow } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { index as recordsIndex } from '@/actions/App/Http/Controllers/Payroll/PayrollRecordController';
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
import { Card, CardContent } from '@/components/ui/card';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { PayrollCategoryBadge } from '../components/payroll-category-badge';
import { formatTimesheetAmount } from '../types';
import type { PayrollCategoryOption } from '../types';
import { PayrollRecordsFiltersSheet } from './components/payroll-records-filters-sheet';
import type { PayrollRecordIndexItem, PayrollRecordsFilters } from './types';

export function PayrollRecordsContent({
    records,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    payroll_categories,
    status_options,
    counts,
}: {
    records: PayrollRecordIndexItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollRecordsFilters;
    payroll_categories: PayrollCategoryOption[];
    status_options: Array<{ value: string; label: string }>;
    counts: { all: number; draft: number; approved: number; paid: number };
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

            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <button
                    type="button"
                    onClick={() =>
                        list.applyFilters({ status: null, page: null })
                    }
                    className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Card
                        className={cn(
                            'glass-card border-border transition-all duration-200 hover:border-border dark:border-white/5 dark:hover:border-white/10',
                            !initialFilters.status &&
                                'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
                        )}
                    >
                        <CardContent className="p-4">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                Total Records
                            </p>
                            <p className="mt-1 text-2xl font-bold text-foreground tabular-nums">
                                {counts.all}
                            </p>
                        </CardContent>
                    </Card>
                </button>

                <button
                    type="button"
                    onClick={() =>
                        list.applyFilters({ status: 'draft', page: null })
                    }
                    className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Card
                        className={cn(
                            'glass-card border-orange-500/20 bg-orange-500/[0.06] transition-all duration-200 hover:border-orange-500/35',
                            initialFilters.status === 'draft' &&
                                'border-orange-500/45 ring-1 ring-orange-500/30',
                        )}
                    >
                        <CardContent className="p-4">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                Draft
                            </p>
                            <p className="mt-1 text-2xl font-bold text-orange-400 tabular-nums">
                                {counts.draft}
                            </p>
                        </CardContent>
                    </Card>
                </button>

                <button
                    type="button"
                    onClick={() =>
                        list.applyFilters({ status: 'approved', page: null })
                    }
                    className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Card
                        className={cn(
                            'glass-card border-sky-500/15 bg-sky-500/[0.04] transition-all duration-200 hover:border-sky-500/30',
                            initialFilters.status === 'approved' &&
                                'border-sky-500/40 ring-1 ring-sky-500/25',
                        )}
                    >
                        <CardContent className="p-4">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                Approved
                            </p>
                            <p className="mt-1 text-2xl font-bold text-sky-400 tabular-nums">
                                {counts.approved}
                            </p>
                        </CardContent>
                    </Card>
                </button>

                <button
                    type="button"
                    onClick={() =>
                        list.applyFilters({ status: 'paid', page: null })
                    }
                    className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Card
                        className={cn(
                            'glass-card border-emerald-500/20 bg-emerald-500/[0.06] transition-all duration-200 hover:border-emerald-500/35',
                            initialFilters.status === 'paid' &&
                                'border-emerald-500/45 ring-1 ring-emerald-500/30',
                        )}
                    >
                        <CardContent className="p-4">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                Paid
                            </p>
                            <p className="mt-1 text-2xl font-bold text-emerald-500 tabular-nums dark:text-emerald-400">
                                {counts.paid}
                            </p>
                        </CardContent>
                    </Card>
                </button>
            </div>

            <SearchBar
                value={list.searchInput}
                onChange={list.onSearchChange}
                placeholder="Search employees..."
                className="mb-6"
                right={
                    <div className="flex items-center rounded-xl glass-card p-1">
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 rounded-lg px-4 hover:bg-accent"
                            onClick={() => setIsFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                            {activeFiltersCount > 0 ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {activeFiltersCount}
                                </span>
                            ) : null}
                        </Button>
                    </div>
                }
            />

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
                                <DataTableHead className="pl-5">
                                    Employee
                                </DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Category</DataTableHead>
                                <DataTableHead>Gross</DataTableHead>
                                <DataTableHead>Net</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {records.map((record) => (
                                <TableRow
                                    key={record.id}
                                    className={cn(
                                        dataTableBodyRowClass(false),
                                        'group transition-colors duration-200 hover:bg-muted/40',
                                    )}
                                >
                                    <TableCell
                                        className={dataTableCellPrimaryClass()}
                                    >
                                        <div className="font-semibold">
                                            {record.employee.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {record.employee.employee_no ?? '—'}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="font-medium">
                                            {record.period.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDisplayDate(
                                                record.period.start_date,
                                            )}{' '}
                                            —{' '}
                                            {formatDisplayDate(
                                                record.period.end_date,
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <PayrollCategoryBadge
                                            category={record.payroll_category}
                                        />
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetAmount(
                                            record.gross_salary,
                                        )}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <span className="font-semibold">
                                            {formatTimesheetAmount(
                                                record.net_salary,
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <span className="text-sm capitalize">
                                            {record.status}
                                        </span>
                                    </TableCell>
                                    <TableCell
                                        className={dataTableActionsCellClass()}
                                    >
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-8 w-8 rounded-lg"
                                            asChild
                                        >
                                            <Link
                                                href={payrollShow.url(
                                                    record.period.id,
                                                )}
                                                aria-label="View period"
                                            >
                                                <Eye className="h-4 w-4 text-muted-foreground transition-colors group-hover:text-foreground" />
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
                        onPageChange={list.goToPage}
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
