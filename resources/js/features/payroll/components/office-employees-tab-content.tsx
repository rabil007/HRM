import { Calculator, Calendar, CalendarDays, CheckSquare } from 'lucide-react';
import type React from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import { cn } from '@/lib/utils';
import { calculateInclusiveDays } from '../lib/calculate-inclusive-days';
import { getPayrollBoardSelectionSummary } from '../lib/payroll-board-selection';
import type {
    CrewPayrollRow,
    EmployeeStats,
    PayrollPeriod,
    PayrollShowFilters,
} from '../types';
import { formatTimesheetDays } from '../types';
import { EmployeeAnalyticsCardsGrid } from './employee-analytics-cards';
import { PayrollBoardFilteredEmptyState } from './payroll-board-filtered-empty-state';
import { PayrollEmployeeCell } from './payroll-employee-cell';
import {
    PayrollRecordBankAccountCell,
    PayrollRecordPaymentMethodCell,
} from './payroll-record-display-cells';
import { SalaryCell } from './salary-cell';

export function OfficeEmployeesTabContent({
    period,
    rows,
    paginationProps,
    allBoardEmployeeIds,
    employee_stats,
    activeEmployeeGroup,
    onEmployeeGroupSelect,
    excludedIds,
    setExcludedIds,
    rowDates,
    setRowDates,
}: {
    period: PayrollPeriod;
    rows: CrewPayrollRow[];
    paginationProps: {
        currentPage: number;
        lastPage: number;
        from: number | null;
        to: number | null;
        total: number;
        perPage: number;
        onPerPageChange: (perPage: number) => void;
        onPageChange: (page: number) => void;
    };
    allBoardEmployeeIds: number[];
    employee_stats: EmployeeStats | null;
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onEmployeeGroupSelect: (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => void;
    excludedIds: Set<number>;
    setExcludedIds: React.Dispatch<React.SetStateAction<Set<number>>>;
    rowDates: Record<number, { start: string; end: string }>;
    setRowDates: React.Dispatch<
        React.SetStateAction<Record<number, { start: string; end: string }>>
    >;
}) {
    const handleStartDateChange = (employeeId: number, val: string) => {
        setRowDates((prev) => ({
            ...prev,
            [employeeId]: {
                start: val,
                end: prev[employeeId]?.end ?? period.end_date,
            },
        }));
    };

    const handleEndDateChange = (employeeId: number, val: string) => {
        setRowDates((prev) => ({
            ...prev,
            [employeeId]: {
                start: prev[employeeId]?.start ?? period.start_date,
                end: val,
            },
        }));
    };

    const selection = getPayrollBoardSelectionSummary({
        pagination: {
            current_page: paginationProps.currentPage,
            last_page: paginationProps.lastPage,
            per_page: paginationProps.perPage,
            total: paginationProps.total,
            from: paginationProps.from,
            to: paginationProps.to,
        },
        allBoardEmployeeIds,
        excludedIds,
        rows,
    });

    const handleSelectAll = (checked: boolean | 'indeterminate') => {
        if (checked === true) {
            setExcludedIds(new Set());
        } else {
            setExcludedIds(new Set(allBoardEmployeeIds));
        }
    };

    const handleRowToggle = (
        employeeId: number,
        checked: boolean | 'indeterminate',
    ) => {
        setExcludedIds((prev) => {
            const next = new Set(prev);

            if (checked === true) {
                next.delete(employeeId);
            } else {
                next.add(employeeId);
            }

            return next;
        });
    };

    const includedCount = selection.includedCount;
    const hasPayRunEmployees = (employee_stats?.total ?? 0) > 0;
    const hasVisibleRows = rows.length > 0;

    return (
        <div className="space-y-6">
            {employee_stats !== null && (
                <EmployeeAnalyticsCardsGrid
                    employee_stats={employee_stats}
                    activeEmployeeGroup={activeEmployeeGroup}
                    onEmployeeGroupSelect={onEmployeeGroupSelect}
                />
            )}

            {!hasPayRunEmployees ? (
                <EmptyState
                    title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                    description={`Only active employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                />
            ) : !hasVisibleRows ? (
                <PayrollBoardFilteredEmptyState
                    activeEmployeeGroup={activeEmployeeGroup}
                    onShowAll={() => onEmployeeGroupSelect('')}
                />
            ) : (
                <>
                    {/* Selection info bar */}
                    <div className="flex items-center justify-between rounded-xl border border-border/40 bg-muted/30 px-4 py-2.5 backdrop-blur-sm">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CheckSquare className="h-4 w-4 shrink-0 text-primary" />
                            <span>
                                <span className="font-semibold text-foreground">
                                    {includedCount}
                                </span>{' '}
                                of{' '}
                                <span className="font-semibold text-foreground">
                                    {selection.totalCount}
                                </span>{' '}
                                employees included
                            </span>
                            {selection.excludedCount > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 border-amber-500/30 bg-amber-500/10 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                                >
                                    {selection.excludedCount} excluded
                                </Badge>
                            )}
                        </div>
                        {selection.excludedCount > 0 && (
                            <button
                                type="button"
                                onClick={() => setExcludedIds(new Set())}
                                className="text-xs font-medium text-primary underline-offset-2 transition-colors hover:underline"
                            >
                                Include all
                            </button>
                        )}
                    </div>

                    {/* Table */}
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                {/* Select-all checkbox */}
                                <DataTableHead className="w-10">
                                    <Checkbox
                                        id="select-all-employees"
                                        checked={selection.headerChecked}
                                        onCheckedChange={handleSelectAll}
                                        aria-label="Select all employees"
                                        className="rounded"
                                    />
                                </DataTableHead>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Bank account</DataTableHead>
                                {/* Salary columns */}
                                <DataTableHead>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="inline-flex cursor-default items-center gap-1.5">
                                                <Calculator className="h-3 w-3 text-primary/60" />
                                                Basic Salary
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            From current contract
                                        </TooltipContent>
                                    </Tooltip>
                                </DataTableHead>
                                <DataTableHead>Housing Allow.</DataTableHead>
                                <DataTableHead>Transport Allow.</DataTableHead>
                                <DataTableHead>Other Allow.</DataTableHead>
                                <DataTableHead>Payment</DataTableHead>
                                <DataTableHead>
                                    <span className="inline-flex cursor-default items-center gap-1.5">
                                        <Calendar className="h-3 w-3 text-primary/60" />
                                        Period (Start — End)
                                    </span>
                                </DataTableHead>
                                <DataTableHead>
                                    <span className="inline-flex cursor-default items-center gap-1.5">
                                        <CalendarDays className="h-3 w-3 text-primary/60" />
                                        Total Days
                                    </span>
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => {
                                const isExcluded = excludedIds.has(
                                    row.employee.id,
                                );
                                const paymentMethod =
                                    (row.salary_payment_method ??
                                        'bank_transfer') as SalaryPaymentMethodValue;
                                const contract = row.contract ?? null;
                                const startDate =
                                    rowDates[row.employee.id]?.start ??
                                    period.start_date;
                                const endDate =
                                    rowDates[row.employee.id]?.end ??
                                    period.end_date;
                                const totalDays = calculateInclusiveDays(
                                    startDate,
                                    endDate,
                                );

                                return (
                                    <TableRow
                                        key={row.employee.id}
                                        className={cn(
                                            dataTableBodyRowClass(),
                                            'group transition-all duration-200',
                                            isExcluded
                                                ? 'bg-muted/20 opacity-40 dark:bg-muted/10'
                                                : 'hover:bg-muted/40',
                                        )}
                                    >
                                        {/* Checkbox */}
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Checkbox
                                                id={`employee-${row.employee.id}`}
                                                checked={!isExcluded}
                                                onCheckedChange={(checked) =>
                                                    handleRowToggle(
                                                        row.employee.id,
                                                        checked,
                                                    )
                                                }
                                                aria-label={`Include ${row.employee.name}`}
                                                className="rounded"
                                            />
                                        </TableCell>

                                        <PayrollEmployeeCell
                                            employee={row.employee}
                                            isExcluded={isExcluded}
                                        />

                                        <PayrollRecordBankAccountCell
                                            primary_account={
                                                row.primary_account ?? null
                                            }
                                            salary_payment_method={
                                                paymentMethod
                                            }
                                        />

                                        {/* Basic salary */}
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-right',
                                            )}
                                        >
                                            <SalaryCell
                                                value={contract?.basic_salary}
                                            />
                                        </TableCell>

                                        {/* Housing allowance */}
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-right',
                                            )}
                                        >
                                            <SalaryCell
                                                value={
                                                    contract?.housing_allowance
                                                }
                                            />
                                        </TableCell>

                                        {/* Transport allowance */}
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-right',
                                            )}
                                        >
                                            <SalaryCell
                                                value={
                                                    contract?.transport_allowance
                                                }
                                            />
                                        </TableCell>

                                        {/* Other allowances */}
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-right',
                                            )}
                                        >
                                            <SalaryCell
                                                value={
                                                    contract?.other_allowances
                                                }
                                            />
                                        </TableCell>

                                        <PayrollRecordPaymentMethodCell
                                            method={paymentMethod}
                                            label={
                                                row.salary_payment_method_label ??
                                                'Bank transfer'
                                            }
                                        />

                                        {/* Period dates */}
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <div className="flex min-w-[310px] items-center gap-2">
                                                <Input
                                                    type="date"
                                                    value={startDate}
                                                    onChange={(e) =>
                                                        handleStartDateChange(
                                                            row.employee.id,
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="h-8 w-[142px] rounded-lg border-border/60 bg-background/50 px-2 font-mono text-xs transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                                <span className="text-xs font-bold text-muted-foreground/50">
                                                    —
                                                </span>
                                                <Input
                                                    type="date"
                                                    value={endDate}
                                                    onChange={(e) =>
                                                        handleEndDateChange(
                                                            row.employee.id,
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="h-8 w-[142px] rounded-lg border-border/60 bg-background/50 px-2 font-mono text-xs transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                            </div>
                                        </TableCell>

                                        {/* Total days */}
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Badge
                                                variant="secondary"
                                                className={cn(
                                                    'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold tabular-nums transition-colors',
                                                    totalDays &&
                                                        Number(totalDays) > 0
                                                        ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                                )}
                                            >
                                                {formatTimesheetDays(totalDays)}{' '}
                                                days
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination {...paginationProps} label="employees" />
                </>
            )}
        </div>
    );
}