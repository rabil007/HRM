import { Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Filter, Plus, Receipt } from 'lucide-react';
import { useState } from 'react';
import {
    index,
    storePeriod,
} from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { show } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
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
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
import { PayrollFiltersSheet } from './components/payroll-filters-sheet';
import { PayrollPeriodCard } from './components/payroll-period-card';
import { PayrollPeriodFormSheet } from './components/payroll-period-form-sheet';
import { PayrollPeriodProgress } from './components/payroll-period-progress';
import { PayrollPeriodStatusBadge } from './components/payroll-period-status-badge';
import { PayrollSummaryCards } from './components/payroll-summary-cards';
import type {
    PayrollCategory,
    PayrollCategoryOption,
    PayrollHubFilters,
    PayrollHubPermissions,
    PayrollHubSummary,
    PayrollPeriodFormData,
    PayrollPeriodListItem,
    PayrollPeriodStatusOption,
} from './types';
import { getPeriodProgressPercent } from './types';

export function PayrollIndexContent({
    periods,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    summary,
    payroll_categories,
    payroll_period_statuses,
    permissions,
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollHubFilters;
    summary: PayrollHubSummary;
    payroll_categories: PayrollCategoryOption[];
    payroll_period_statuses: PayrollPeriodStatusOption[];
    permissions: PayrollHubPermissions;
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [view, setView] = useViewPreference('payroll:view', 'grid');

    const list = useServerPaginationFilters({
        url: index.url(),
        search: initialSearch,
        filters: {
            category: initialFilters.category,
            status: initialFilters.status,
            date_from: initialFilters.date_from,
            date_to: initialFilters.date_to,
        },
        pagination,
    });

    const form = useForm<PayrollPeriodFormData>({
        name: '',
        payroll_category: 'crew',
        crew_timesheet_mode: 'manual',
        start_date: '',
        end_date: '',
        notes: '',
    });

    const canOpen =
        permissions.view_crew_timesheets || permissions.create_period;

    const handleAdd = () => {
        form.reset();
        form.clearErrors();
        setIsSheetOpen(true);
    };

    const handleSubmit = () => {
        form.post(storePeriod.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const handleFiltersChange = (next: PayrollHubFilters) => {
        list.applyFilters(next);
    };

    const handleCategoryChange = (category: PayrollCategory | '') => {
        list.applyFilters({ category });
    };

    const activeFiltersCount = [
        initialFilters.category,
        initialFilters.status,
        initialFilters.date_from,
        initialFilters.date_to,
    ].filter(Boolean).length;

    const hasActiveFilters = Boolean(
        initialFilters.category ||
        initialFilters.status ||
        initialFilters.date_from ||
        initialFilters.date_to ||
        initialSearch,
    );

    return (
        <Main>
            <PageHeader
                kicker="Payroll"
                title="Pay runs"
                description="Create pay periods, enter crew timesheets, and prepare office payroll from approved leave usage."
                right={
                    permissions.create_period ? (
                        <Button
                            onClick={handleAdd}
                            className="h-12 rounded-xl bg-gradient-to-r from-primary to-primary/80 px-6 text-primary-foreground shadow-lg shadow-primary/25 transition-all duration-300 hover:scale-105 hover:from-primary/90 hover:to-primary active:scale-95"
                        >
                            <Plus className="mr-2 h-4 w-4 drop-shadow-sm" />
                            New pay period
                        </Button>
                    ) : null
                }
            />

            <PayrollSummaryCards
                summary={summary}
                activeCategory={initialFilters.category}
                onSelect={handleCategoryChange}
            />

            <SearchBar
                placeholder="Search pay runs by name..."
                value={list.searchInput}
                onChange={list.onSearchChange}
                right={
                    <div className="flex flex-wrap items-center gap-2">
                        <ViewToggle value={view} onChange={setView} />
                        <div className="flex items-center rounded-xl glass-card p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11 rounded-lg px-4 hover:bg-accent"
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
                    </div>
                }
            />

            {periods.length === 0 ? (
                <EmptyState
                    icon={
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl border border-border/60 bg-muted/30 dark:border-white/10 dark:bg-white/5">
                            <Receipt className="h-6 w-6 text-muted-foreground" />
                        </div>
                    }
                    title={
                        hasActiveFilters
                            ? 'No matching pay runs'
                            : 'No pay periods yet'
                    }
                    description={
                        hasActiveFilters
                            ? 'Try a different search term or clear the filters.'
                            : 'Create a draft pay period to start entering crew timesheets or preparing office payroll.'
                    }
                    action={
                        permissions.create_period ? (
                            <Button
                                onClick={handleAdd}
                                className="rounded-xl bg-gradient-to-r from-primary to-primary/80 text-primary-foreground shadow-md shadow-primary/25 transition-all duration-300 hover:scale-105 hover:from-primary/90 hover:to-primary active:scale-95"
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                New pay period
                            </Button>
                        ) : undefined
                    }
                />
            ) : view === 'grid' ? (
                <>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                        {periods.map((period) => (
                            <PayrollPeriodCard
                                key={period.id}
                                period={period}
                                canOpen={canOpen}
                            />
                        ))}
                    </div>

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
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[1080px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="pl-5">
                                    Pay run
                                </DataTableHead>
                                <DataTableHead>Type</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Payment</DataTableHead>
                                <DataTableHead>Progress</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {periods.map((period) => {
                                const progress =
                                    getPeriodProgressPercent(period);

                                return (
                                    <TableRow
                                        key={period.id}
                                        className={cn(
                                            dataTableBodyRowClass(canOpen),
                                            'group transition-colors duration-200 hover:bg-muted/40',
                                            canOpen && 'cursor-pointer',
                                        )}
                                        onClick={
                                            canOpen
                                                ? () =>
                                                      router.visit(
                                                          show.url(period.id),
                                                      )
                                                : undefined
                                        }
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            <div className="font-semibold">
                                                {period.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {period.employee_count}{' '}
                                                {period.payroll_category_label.toLowerCase()}{' '}
                                                employees
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <PayrollCategoryBadge
                                                category={
                                                    period.payroll_category
                                                }
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {formatDisplayDate(
                                                period.start_date,
                                            )}{' '}
                                            —{' '}
                                            {formatDisplayDate(period.end_date)}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {period.payment_date
                                                ? formatDisplayDate(
                                                      period.payment_date,
                                                  )
                                                : 'Pending'}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'min-w-[180px]',
                                            )}
                                        >
                                            {period.supports_timesheets ? (
                                                <div className="space-y-2 pr-4">
                                                    <div className="flex items-center justify-between text-xs font-semibold text-muted-foreground">
                                                        <span>
                                                            {
                                                                period.timesheets_progress_label
                                                            }{' '}
                                                            filled
                                                        </span>
                                                        <span>{progress}%</span>
                                                    </div>
                                                    <PayrollPeriodProgress
                                                        value={progress}
                                                    />
                                                </div>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    Leave-based payroll
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <PayrollPeriodStatusBadge
                                                status={period.status}
                                                label={period.status_label}
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={dataTableActionsCellClass()}
                                        >
                                            {canOpen ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="rounded-lg"
                                                    asChild
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                >
                                                    <Link
                                                        href={show.url(
                                                            period.id,
                                                        )}
                                                    >
                                                        Open
                                                        <ChevronRight className="ml-2 h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
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

            <PayrollPeriodFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                form={form}
                payrollCategories={payroll_categories}
                onSubmit={handleSubmit}
            />

            <PayrollFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                payrollCategories={payroll_categories}
                payrollPeriodStatuses={payroll_period_statuses}
                value={{
                    category: initialFilters.category,
                    status: initialFilters.status,
                    date_from: initialFilters.date_from,
                    date_to: initialFilters.date_to,
                }}
                onChange={handleFiltersChange}
                onReset={() =>
                    handleFiltersChange({
                        category: '',
                        status: '',
                        date_from: '',
                        date_to: '',
                    })
                }
            />
        </Main>
    );
}
