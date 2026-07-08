import { CalendarDays, Plus, Trash2 } from 'lucide-react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { OfficePayrollRecordListItem, SalaryInput } from '../types';
import { formatTimesheetAmount } from '../types';
import { PayrollEmployeeCell } from './payroll-employee-cell';
import {
    PayrollRecordBankAccountCell,
    PayrollRecordPaymentMethodCell,
} from './payroll-record-display-cells';
import {
    PayrollRecordPayslipActionButtons,
    PayrollRecordPayslipStatusCell,
} from './payroll-record-payslip-cells';
import { PayrollRecordSalaryInputsCell } from './payroll-record-salary-inputs-cell';

import {
    PayrollRecordWpsSelectionCell,
    PayrollRecordWpsSelectionHead,
} from './payroll-record-wps-selection';

export function OfficePayrollRecordsTable({
    records,
    salaryInputsByEmployee,
    canManageSalaryInputs,
    canRemove,
    wpsSelection,
    onManageSalaryInputs,
    onRemove,
    isPayslipGenerationLive = false,
}: {
    records: OfficePayrollRecordListItem[];
    salaryInputsByEmployee: Record<string, SalaryInput[]>;
    canManageSalaryInputs: boolean;
    canRemove: boolean;
    isPayslipGenerationLive?: boolean;
    wpsSelection?: {
        selectedRecordIds: number[];
        allSelected: boolean;
        someSelected: boolean;
        onToggleRecord: (recordId: number) => void;
        onToggleAll: () => void;
    };
    onManageSalaryInputs: (record: OfficePayrollRecordListItem) => void;
    onRemove: (record: OfficePayrollRecordListItem) => void;
}) {
    const showSalaryInputsColumn = Object.values(salaryInputsByEmployee).some(
        (inputs) => inputs.length > 0,
    );

    return (
        <OrganizationDataTable
            minWidth={
                showSalaryInputsColumn
                    ? wpsSelection
                        ? 'min-w-[1640px]'
                        : 'min-w-[1600px]'
                    : wpsSelection
                      ? 'min-w-[1480px]'
                      : 'min-w-[1440px]'
            }
        >
            <TableHeader>
                <DataTableHeaderRow>
                    {wpsSelection ? (
                        <PayrollRecordWpsSelectionHead
                            allSelected={wpsSelection.allSelected}
                            someSelected={wpsSelection.someSelected}
                            onToggleAll={wpsSelection.onToggleAll}
                        />
                    ) : null}
                    <DataTableHead
                        className={wpsSelection ? undefined : 'pl-5'}
                    >
                        Employee
                    </DataTableHead>
                    <DataTableHead>Bank account</DataTableHead>
                    <DataTableHead>Payment</DataTableHead>
                    <DataTableHead>
                        <span className="inline-flex cursor-default items-center gap-1.5">
                            <CalendarDays className="h-3 w-3 text-primary/60" />
                            Total Days
                        </span>
                    </DataTableHead>
                    <DataTableHead>Basic</DataTableHead>
                    <DataTableHead>Housing</DataTableHead>
                    <DataTableHead>Transport</DataTableHead>
                    <DataTableHead>Other</DataTableHead>
                    {showSalaryInputsColumn ? (
                        <DataTableHead>Salary inputs</DataTableHead>
                    ) : null}
                    <DataTableHead>Gross</DataTableHead>
                    <DataTableHead>Net</DataTableHead>
                    <DataTableHead>Payslip</DataTableHead>
                    <DataTableHead className={dataTableActionsCellClass()}>
                        Actions
                    </DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {records.map((record) => {
                    const isSelected =
                        wpsSelection?.selectedRecordIds.includes(record.id) ??
                        false;

                    return (
                        <TableRow
                            key={record.id}
                            className={cn(
                                dataTableBodyRowClass(false),
                                isSelected && '[&>td]:bg-primary/5',
                            )}
                        >
                            {wpsSelection ? (
                                <PayrollRecordWpsSelectionCell
                                    checked={isSelected}
                                    employeeName={record.employee.name}
                                    onToggle={() =>
                                        wpsSelection.onToggleRecord(record.id)
                                    }
                                />
                            ) : null}
                            <PayrollEmployeeCell
                                employee={record.employee}
                                className={!wpsSelection ? 'pl-5' : undefined}
                            />
                            <PayrollRecordBankAccountCell
                                primary_account={record.primary_account}
                                salary_payment_method={
                                    (record.salary_payment_method ??
                                        'bank_transfer') as SalaryPaymentMethodValue
                                }
                            />
                            <PayrollRecordPaymentMethodCell
                                method={
                                    (record.salary_payment_method ??
                                        'bank_transfer') as SalaryPaymentMethodValue
                                }
                                label={
                                    record.salary_payment_method_label ??
                                    'Bank transfer'
                                }
                            />
                            <TableCell className={dataTableCellClass()}>
                                <div className="flex flex-col gap-0.5">
                                    <Badge
                                        variant="secondary"
                                        className="inline-flex w-fit items-center gap-1 rounded-lg border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 tabular-nums dark:text-emerald-300"
                                    >
                                        {record.present_days ??
                                            record.working_days ??
                                            '—'}{' '}
                                        / {record.working_days ?? '—'} days
                                    </Badge>
                                    {record.absent_days &&
                                    record.absent_days > 0 &&
                                    Number(record.unpaid_leave_deduction) >
                                        0 ? (
                                        <span className="text-[11px] font-medium text-destructive">
                                            {record.absent_days} day
                                            {record.absent_days > 1 ? 's' : ''}{' '}
                                            deducted (
                                            {formatTimesheetAmount(
                                                record.unpaid_leave_deduction,
                                            )}
                                            )
                                        </span>
                                    ) : null}
                                </div>
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {formatTimesheetAmount(record.basic_salary)}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {formatTimesheetAmount(
                                    record.housing_allowance,
                                )}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {formatTimesheetAmount(
                                    record.transport_allowance,
                                )}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {formatTimesheetAmount(record.other_allowances)}
                            </TableCell>
                            {showSalaryInputsColumn ? (
                                <PayrollRecordSalaryInputsCell
                                    inputs={
                                        salaryInputsByEmployee[
                                            String(record.employee.id)
                                        ] ?? []
                                    }
                                />
                            ) : null}
                            <TableCell className={dataTableCellClass()}>
                                {formatTimesheetAmount(record.gross_salary)}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <span className="font-semibold">
                                    {formatTimesheetAmount(record.net_salary)}
                                </span>
                            </TableCell>
                            <PayrollRecordPayslipStatusCell
                                has_payslip={record.has_payslip}
                                wps_status_label={record.wps_status_label}
                                isLiveUpdating={isPayslipGenerationLive}
                            />
                            <TableCell className={dataTableActionsCellClass()}>
                                <div className="flex items-center justify-end gap-2">
                                    {canManageSalaryInputs ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="relative size-8 rounded-lg"
                                                    aria-label="Salary inputs"
                                                    onClick={() =>
                                                        onManageSalaryInputs(
                                                            record,
                                                        )
                                                    }
                                                >
                                                    <Plus className="h-4 w-4" />
                                                    {(
                                                        salaryInputsByEmployee[
                                                            String(
                                                                record.employee
                                                                    .id,
                                                            )
                                                        ] ?? []
                                                    ).length > 0 ? (
                                                        <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-none font-bold text-primary-foreground">
                                                            {
                                                                (
                                                                    salaryInputsByEmployee[
                                                                        String(
                                                                            record
                                                                                .employee
                                                                                .id,
                                                                        )
                                                                    ] ?? []
                                                                ).length
                                                            }
                                                        </span>
                                                    ) : null}
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                Salary inputs
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : null}
                                    <PayrollRecordPayslipActionButtons
                                        recordId={record.id}
                                        has_payslip={record.has_payslip}
                                    />
                                    {canRemove ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg text-destructive hover:text-destructive"
                                                    aria-label="Remove from pay run"
                                                    onClick={() =>
                                                        onRemove(record)
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                Remove from pay run
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : null}
                                </div>
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </OrganizationDataTable>
    );
}
