import { CheckSquare } from 'lucide-react';
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
import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import { cn } from '@/lib/utils';
import { getPayrollBoardSelectionSummary } from '../lib/payroll-board-selection';
import type {
    CrewPayrollRow,
    CrewTimesheetDraft,
    EmployeeStats,
    PayrollPeriod,
    PayrollShowFilters,
} from '../types';
import type { PaginationMeta } from '@/types/pagination';
import { CrewOperationalSourceBadge } from './crew-operational-source-badge';
import { CrewRangeEditor } from './crew-range-editor';
import { CrewTimesheetApprovalBadge } from './crew-timesheet-approval-badge';
import { EmployeeAnalyticsCardsGrid } from './employee-analytics-cards';
import { OperationalDateRange } from './operational-date-range';
import { PayrollBoardFilteredEmptyState } from './payroll-board-filtered-empty-state';
import { PayrollEmployeeCell } from './payroll-employee-cell';
import {
    PayrollRecordBankAccountCell,
    PayrollRecordPaymentMethodCell,
} from './payroll-record-display-cells';
import { SalaryCell } from './salary-cell';

export type CrewTimesheetsBoardPaginationProps = {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    perPage: number;
    onPerPageChange: (perPage: number) => void;
    onPageChange: (page: number) => void;
};

export type CrewTimesheetsBoardProps = {
    period: PayrollPeriod;
    rows: CrewPayrollRow[];
    pagination: PaginationMeta;
    paginationProps: CrewTimesheetsBoardPaginationProps;
    allBoardEmployeeIds: number[];
    excludedIds: Set<number>;
    setExcludedIds: React.Dispatch<React.SetStateAction<Set<number>>>;
    employee_stats: EmployeeStats | null;
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onEmployeeGroupSelect: (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => void;
    crewTimesheetDrafts: Record<number, CrewTimesheetDraft>;
    onCrewTimesheetChange: (
        employeeId: number,
        field: keyof CrewTimesheetDraft,
        val: string,
        initialTimesheet: CrewPayrollRow['timesheet'],
    ) => void;
    savingTimesheetEmployeeIds: number[];
    canEditTimesheets: boolean;
};

export function CrewTimesheetsBoard({
    period,
    rows,
    pagination,
    paginationProps,
    allBoardEmployeeIds,
    excludedIds,
    setExcludedIds,
    employee_stats,
    activeEmployeeGroup,
    onEmployeeGroupSelect,
    crewTimesheetDrafts,
    onCrewTimesheetChange,
    savingTimesheetEmployeeIds,
    canEditTimesheets,
}: CrewTimesheetsBoardProps) {
        const allIds = allBoardEmployeeIds;
        const selection = getPayrollBoardSelectionSummary({
            pagination,
            allBoardEmployeeIds: allIds,
            excludedIds,
            rows,
        });

        const handleSelectAll = (checked: boolean | 'indeterminate') => {
            if (checked === true) {
                setExcludedIds(new Set());
            } else {
                setExcludedIds(new Set(allIds));
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

                        <OrganizationDataTable minWidth="min-w-[1540px]">
                            <TableHeader>
                                {/* Group labels row */}
                                <tr className="border-b-0">
                                    <th
                                        colSpan={2}
                                        className="h-7 border-b border-border/30"
                                    />
                                    <th
                                        colSpan={1}
                                        className="h-7 border-b border-border/30"
                                    />
                                    <th
                                        colSpan={3}
                                        className="h-7 border-x border-b border-primary/15 bg-primary/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-primary/50 uppercase"
                                    >
                                        Daily Rates
                                    </th>
                                    <th
                                        colSpan={2}
                                        className="h-7 border-x border-b border-blue-500/15 bg-blue-500/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-blue-600/60 uppercase dark:text-blue-400/60"
                                    >
                                        Days
                                    </th>
                                    <th
                                        colSpan={1}
                                        className="h-7 border-x border-b border-amber-500/15 bg-amber-500/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-amber-600/60 uppercase dark:text-amber-400/60"
                                    >
                                        Overtime
                                    </th>
                                    <th
                                        colSpan={3}
                                        className="h-7 border-b border-border/30"
                                    />
                                </tr>
                                <DataTableHeaderRow>
                                    <DataTableHead className="w-10">
                                        <Checkbox
                                            id="select-all-crew-employees"
                                            checked={selection.headerChecked}
                                            onCheckedChange={handleSelectAll}
                                            aria-label="Select all employees"
                                            className="rounded"
                                        />
                                    </DataTableHead>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead>Bank</DataTableHead>
                                    <DataTableHead className="border-l border-primary/10 bg-primary/3 text-right">
                                        Basic
                                    </DataTableHead>
                                    <DataTableHead className="bg-primary/3 text-right">
                                        Supplementary
                                    </DataTableHead>
                                    <DataTableHead className="border-r border-primary/10 bg-primary/3 text-right">
                                        Site
                                    </DataTableHead>
                                    <DataTableHead className="border-l border-blue-500/10 bg-blue-500/3">
                                        Standby
                                    </DataTableHead>
                                    <DataTableHead className="border-r border-blue-500/10 bg-blue-500/3">
                                        Onsite
                                    </DataTableHead>
                                    <DataTableHead className="border-x border-amber-500/10 bg-amber-500/3 text-right">
                                        Overtime
                                    </DataTableHead>
                                    <DataTableHead>Payment</DataTableHead>
                                    <DataTableHead>
                                        Timesheet Status
                                    </DataTableHead>
                                    <DataTableHead>Source</DataTableHead>
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
                                    const isMonthlyCrewRow =
                                        row.salary_structure === 'monthly';

                                    const currentDraft =
                                        crewTimesheetDrafts[row.employee.id];
                                    const signOnFrom =
                                        currentDraft?.sign_on_standby_from ??
                                        row.timesheet?.sign_on_standby_from ??
                                        '';
                                    const signOnTo =
                                        currentDraft?.sign_on_standby_to ??
                                        row.timesheet?.sign_on_standby_to ??
                                        '';
                                    const onsiteFrom =
                                        currentDraft?.onsite_from ??
                                        row.timesheet?.onsite_from ??
                                        '';
                                    const onsiteTo =
                                        currentDraft?.onsite_to ??
                                        row.timesheet?.onsite_to ??
                                        '';
                                    const signOffFrom =
                                        currentDraft?.sign_off_standby_from ??
                                        row.timesheet?.sign_off_standby_from ??
                                        '';
                                    const signOffTo =
                                        currentDraft?.sign_off_standby_to ??
                                        row.timesheet?.sign_off_standby_to ??
                                        '';
                                    const unpaidLeaveDays =
                                        currentDraft?.unpaid_leave_days ??
                                        row.timesheet?.unpaid_leave_days ??
                                        '';
                                    const overtimeHours =
                                        currentDraft?.overtime_hours ??
                                        row.timesheet?.overtime_hours ??
                                        '';

                                    const isOperationallyLocked =
                                        !isMonthlyCrewRow &&
                                        row.timesheet
                                            ?.is_operationally_locked === true;

                                    const isDirty =
                                        !!crewTimesheetDrafts[row.employee.id];
                                    const isSaving =
                                        savingTimesheetEmployeeIds.includes(
                                            row.employee.id,
                                        );
                                    const operationalSource =
                                        row.operational_source ??
                                        (isMonthlyCrewRow
                                            ? 'monthly_crew'
                                            : row.timesheet
                                              ? ((row.timesheet.source as
                                                    | 'crew_operations'
                                                    | 'import'
                                                    | 'manual') ?? 'manual')
                                              : 'not_entered');

                                    return (
                                        <TableRow
                                            key={row.employee.id}
                                            className={cn(
                                                dataTableBodyRowClass(),
                                                'group transition-all duration-200',
                                                isExcluded
                                                    ? 'bg-muted/10 opacity-35 dark:bg-muted/5'
                                                    : 'hover:bg-muted/30',
                                                isDirty &&
                                                    !isExcluded &&
                                                    'ring-1 ring-primary/20 ring-inset',
                                            )}
                                        >
                                            {/* Checkbox */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'pl-4',
                                                )}
                                            >
                                                <Checkbox
                                                    id={`crew-employee-${row.employee.id}`}
                                                    checked={!isExcluded}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
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

                                            {/* Bank account */}
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
                                                    'border-l border-primary/8 bg-primary/2 text-right',
                                                )}
                                            >
                                                <SalaryCell
                                                    value={
                                                        contract?.basic_salary
                                                    }
                                                />
                                            </TableCell>

                                            {/* Supplementary */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'bg-primary/2 text-right',
                                                )}
                                            >
                                                <SalaryCell
                                                    value={
                                                        contract?.supplementary_allowance
                                                    }
                                                />
                                            </TableCell>

                                            {/* Site allowance */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-r border-primary/8 bg-primary/2 text-right',
                                                )}
                                            >
                                                <SalaryCell
                                                    value={
                                                        contract?.site_allowance
                                                    }
                                                />
                                            </TableCell>

                                            {/* Sign-on / Sign-off standby (daily) or Unpaid leave (monthly) */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-l border-blue-500/8 bg-blue-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <div className="flex flex-col gap-1">
                                                        <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                                                            Unpaid leave days
                                                        </p>
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            inputMode="decimal"
                                                            placeholder="0"
                                                            value={
                                                                unpaidLeaveDays
                                                            }
                                                            onChange={(e) =>
                                                                onCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'unpaid_leave_days',
                                                                    e.target
                                                                        .value,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            className="h-8 w-[110px] rounded-md border-border/50 bg-background/60 px-2 font-mono text-[11px] tabular-nums shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50"
                                                            aria-label={`Unpaid leave days for ${row.employee.name}`}
                                                        />
                                                    </div>
                                                ) : isOperationallyLocked ? (
                                                    <div className="space-y-2 text-[11px]">
                                                        <OperationalDateRange
                                                            label="Sign-on standby"
                                                            from={
                                                                row.timesheet
                                                                    ?.sign_on_standby_from
                                                            }
                                                            to={
                                                                row.timesheet
                                                                    ?.sign_on_standby_to
                                                            }
                                                            days={
                                                                row.timesheet
                                                                    ?.sign_on_standby_days
                                                            }
                                                        />
                                                        <OperationalDateRange
                                                            label="Sign-off standby"
                                                            from={
                                                                row.timesheet
                                                                    ?.sign_off_standby_from
                                                            }
                                                            to={
                                                                row.timesheet
                                                                    ?.sign_off_standby_to
                                                            }
                                                            days={
                                                                row.timesheet
                                                                    ?.sign_off_standby_days
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        <CrewRangeEditor
                                                            label="Sign-on standby"
                                                            from={signOnFrom}
                                                            to={signOnTo}
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            onFromChange={(v) =>
                                                                onCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_on_standby_from',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            onToChange={(v) =>
                                                                onCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_on_standby_to',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                        />
                                                        <CrewRangeEditor
                                                            label="Sign-off standby"
                                                            from={signOffFrom}
                                                            to={signOffTo}
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            onFromChange={(v) =>
                                                                onCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_off_standby_from',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            onToChange={(v) =>
                                                                onCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_off_standby_to',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </TableCell>

                                            {/* Onsite dates */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-r border-blue-500/8 bg-blue-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : isOperationallyLocked ? (
                                                    <OperationalDateRange
                                                        label="Onsite"
                                                        from={
                                                            row.timesheet
                                                                ?.onsite_from
                                                        }
                                                        to={
                                                            row.timesheet
                                                                ?.onsite_to
                                                        }
                                                        days={
                                                            row.timesheet
                                                                ?.onsite_days
                                                        }
                                                    />
                                                ) : (
                                                    <CrewRangeEditor
                                                        label="Onsite"
                                                        from={onsiteFrom}
                                                        to={onsiteTo}
                                                        disabled={
                                                            !canEditTimesheets
                                                        }
                                                        activeColorClass="border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                                                        onFromChange={(v) =>
                                                            onCrewTimesheetChange(
                                                                row.employee.id,
                                                                'onsite_from',
                                                                v,
                                                                row.timesheet,
                                                            )
                                                        }
                                                        onToChange={(v) =>
                                                            onCrewTimesheetChange(
                                                                row.employee.id,
                                                                'onsite_to',
                                                                v,
                                                                row.timesheet,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </TableCell>

                                            {/* Overtime hours */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-x border-amber-500/8 bg-amber-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        placeholder="0"
                                                        value={overtimeHours}
                                                        onChange={(e) =>
                                                            onCrewTimesheetChange(
                                                                row.employee.id,
                                                                'overtime_hours',
                                                                e.target.value,
                                                                row.timesheet,
                                                            )
                                                        }
                                                        disabled={
                                                            !canEditTimesheets
                                                        }
                                                        className="h-8 w-[110px] rounded-md border-border/50 bg-background/60 px-2 font-mono text-[11px] tabular-nums shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50"
                                                        aria-label={`Overtime hours for ${row.employee.name}`}
                                                    />
                                                )}
                                            </TableCell>

                                            {/* Payment method */}
                                            <PayrollRecordPaymentMethodCell
                                                method={paymentMethod}
                                                label={
                                                    row.salary_payment_method_label ??
                                                    'Bank transfer'
                                                }
                                            />

                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'align-middle',
                                                )}
                                            >
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <CrewTimesheetApprovalBadge
                                                        status={
                                                            row.approval_status
                                                        }
                                                        label={
                                                            row.approval_status_label
                                                        }
                                                    />
                                                    {isSaving ? (
                                                        <span className="text-[10px] font-medium text-muted-foreground">
                                                            Saving…
                                                        </span>
                                                    ) : isDirty ? (
                                                        <span className="inline-flex items-center gap-1 text-[10px] font-medium text-primary/70">
                                                            <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-primary/60" />
                                                            Unsaved
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>

                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'align-middle',
                                                )}
                                            >
                                                {operationalSource ===
                                                'not_entered' ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <CrewOperationalSourceBadge
                                                        source={
                                                            operationalSource
                                                        }
                                                        label={
                                                            row.operational_source_label
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </OrganizationDataTable>

                        <Pagination
                            {...paginationProps}
                            label="employees"
                        />
                    </>
                )}
            </div>
        );}
