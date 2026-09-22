import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    Loader2,
} from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { exportMethod } from '@/routes/organization/reports/leave-balances';
import { LeaveBalanceReportTable } from './report-table';
import type { LeaveBalanceReportProps } from './types';
import { useLeaveBalanceReportFilters } from './use-leave-balance-report-filters';

function formatDays(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function LeaveBalanceReportContent(props: LeaveBalanceReportProps) {
    const {
        balances,
        pagination,
        summary,
        filters,
        filter_options: options,
        can,
    } = props;
    const controls = useLeaveBalanceReportFilters(filters, pagination.per_page);
    const hasActiveFilters =
        filters.search !== '' ||
        filters.employee_id !== '' ||
        filters.department_id !== '' ||
        filters.leave_type_id !== '' ||
        filters.category !== '' ||
        filters.employee_status !== '' ||
        controls.searchInput.trim() !== '';

    const exportUrl = (format: 'xlsx' | 'csv'): string =>
        exportMethod.url({
            query: {
                ...Object.fromEntries(
                    Object.entries(filters).filter(([, value]) => value !== ''),
                ),
                format,
            },
        });

    return (
        <Main>
            <PageHeader
                kicker="Reports"
                title="Leave Balance Report"
                description="Review persisted leave entitlement, usage, and remaining balances. Opening this report does not create or repair balances."
                right={
                    can.export ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <Download className="mr-2 size-4" />
                                    Export report
                                    <ChevronDown className="ml-2 size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('xlsx')}>
                                        <FileSpreadsheet className="size-4" />
                                        Excel workbook
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('csv')}>
                                        <FileText className="size-4" />
                                        CSV file
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null
                }
            />

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {[
                    ['Employees', summary.employees.toLocaleString()],
                    ['Balance rows', summary.balance_rows.toLocaleString()],
                    ['Used days', formatDays(summary.used_days)],
                    ['Pending days', formatDays(summary.pending_days)],
                ].map(([label, value]) => (
                    <Card key={label}>
                        <CardContent className="p-3">
                            <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {label}
                            </p>
                            <p className="mt-1 text-xl font-bold tabular-nums">
                                {value}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="mt-6">
                <SearchBar
                    placeholder="Search employee name or number..."
                    value={controls.searchInput}
                    onChange={controls.changeSearch}
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            <AppSelect
                                value={filters.year}
                                onValueChange={(value) =>
                                    controls.apply({ year: value })
                                }
                                variant="dark"
                                placeholder="Year"
                                className="h-11 w-[7rem]"
                            >
                                {options.years.map((year) => (
                                    <AppSelectItem
                                        key={year}
                                        value={String(year)}
                                    >
                                        {year}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <AppSelect
                                value={filters.department_id}
                                onValueChange={(value) =>
                                    controls.apply({ department_id: value })
                                }
                                variant="dark"
                                placeholder="All departments"
                                className="h-11 w-[12rem]"
                            >
                                <AppSelectItem value="">
                                    All departments
                                </AppSelectItem>
                                {options.departments.map((department) => (
                                    <AppSelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <AppSelect
                                value={filters.leave_type_id}
                                onValueChange={(value) =>
                                    controls.apply({ leave_type_id: value })
                                }
                                variant="dark"
                                placeholder="All leave types"
                                className="h-11 w-[12rem]"
                            >
                                <AppSelectItem value="">
                                    All leave types
                                </AppSelectItem>
                                {options.leave_types.map((leaveType) => (
                                    <AppSelectItem
                                        key={leaveType.id}
                                        value={String(leaveType.id)}
                                    >
                                        {leaveType.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <AppSelect
                                value={filters.category}
                                onValueChange={(value) =>
                                    controls.apply({ category: value })
                                }
                                variant="dark"
                                placeholder="All categories"
                                className="h-11 w-[10rem]"
                            >
                                <AppSelectItem value="">
                                    All categories
                                </AppSelectItem>
                                {options.categories.map((category) => (
                                    <AppSelectItem
                                        key={category.value}
                                        value={category.value}
                                    >
                                        {category.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <AppSelect
                                value={filters.employee_status}
                                onValueChange={(value) =>
                                    controls.apply({ employee_status: value })
                                }
                                variant="dark"
                                placeholder="All statuses"
                                className="h-11 w-[10rem]"
                            >
                                <AppSelectItem value="">
                                    All statuses
                                </AppSelectItem>
                                {options.employee_statuses.map((status) => (
                                    <AppSelectItem
                                        key={status.value}
                                        value={status.value}
                                    >
                                        {status.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <AppSelect
                                value={filters.employee_id}
                                onValueChange={(value) =>
                                    controls.apply({ employee_id: value })
                                }
                                variant="dark"
                                placeholder="All employees"
                                className="h-11 w-[12rem]"
                            >
                                <AppSelectItem value="">
                                    All employees
                                </AppSelectItem>
                                {options.employees.map((employee) => (
                                    <AppSelectItem
                                        key={employee.id}
                                        value={String(employee.id)}
                                    >
                                        {employee.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {controls.isLoading ? (
                                <Loader2 className="size-4 animate-spin text-muted-foreground" />
                            ) : null}
                            {hasActiveFilters ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={controls.clear}
                                >
                                    Clear filters
                                </Button>
                            ) : null}
                        </div>
                    }
                />
            </div>

            <div className="mt-4">
                {balances.length === 0 ? (
                    <EmptyState
                        title="No leave balances found"
                        description="This report only shows balances that already exist. It does not create missing balances."
                    />
                ) : (
                    <LeaveBalanceReportTable
                        rows={balances}
                        total={pagination.total}
                    />
                )}
            </div>

            <Pagination
                currentPage={pagination.current_page}
                lastPage={pagination.last_page}
                from={pagination.from}
                to={pagination.to}
                total={pagination.total}
                perPage={pagination.per_page}
                perPageOptions={[25, 50, 100]}
                onPageChange={controls.page}
                onPerPageChange={controls.perPage}
                label="balance rows"
            />
        </Main>
    );
}
