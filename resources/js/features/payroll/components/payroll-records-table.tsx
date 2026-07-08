import { Plus, Trash2 } from 'lucide-react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
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
import type { CrewPayrollRecordListItem, SalaryInput } from '../types';
import { formatTimesheetAmount } from '../types';
import {
    CrewOvertimeColumnCell,
    CrewPayColumnCell,
} from './crew-rate-allowance-cell';
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

export function PayrollRecordsTable({
    records,
    salaryInputsByEmployee,
    canManageSalaryInputs,
    canRemove,
    wpsSelection,
    onManageSalaryInputs,
    onRemove,
    isPayslipGenerationLive = false,
}: {
    records: CrewPayrollRecordListItem[];
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
    onManageSalaryInputs: (record: CrewPayrollRecordListItem) => void;
    onRemove: (record: CrewPayrollRecordListItem) => void;
}) {
    const showSalaryInputsColumn = Object.values(salaryInputsByEmployee).some(
        (inputs) => inputs.length > 0,
    );

    // col counts for group headers
    const fixedCols = wpsSelection ? 4 : 3; // checkbox? + employee + bank + payment

    return (
        <OrganizationDataTable
            minWidth={wpsSelection ? 'min-w-[1520px]' : 'min-w-[1460px]'}
        >
            <TableHeader>
                {/* Group label row */}
                <tr className="border-b-0">
                    {/* fixed cols placeholder */}
                    {Array.from({ length: fixedCols }).map((_, i) => (
                        <th
                            key={i}
                            className="h-7 border-b border-border/30"
                        />
                    ))}
                    {/* Crew salary breakdown */}
                    <th
                        colSpan={3}
                        className="h-7 border-x border-b border-primary/15 bg-primary/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-primary/50 uppercase"
                    >
                        Salary breakdown
                    </th>
                    {showSalaryInputsColumn ? (
                        <th className="h-7 border-b border-border/30" />
                    ) : null}
                    <th
                        colSpan={2}
                        className="h-7 border-b border-border/30"
                    />
                    <th className="h-7 border-b border-border/30" />
                </tr>

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
                    <DataTableHead className="border-l border-primary/10 bg-primary/3">
                        Stand By
                    </DataTableHead>
                    <DataTableHead className="border-x border-primary/10 bg-primary/3">
                        On Site
                    </DataTableHead>
                    <DataTableHead className="border-r border-primary/10 bg-primary/3">
                        Overtime
                    </DataTableHead>
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
                    const paymentMethod = (record.salary_payment_method ??
                        'bank_transfer') as SalaryPaymentMethodValue;
                    const netAmount = Number(record.net_salary ?? 0);
                    const grossAmount = Number(record.gross_salary ?? 0);

                    const standbyDays = Number(record.standby_days ?? 0);
                    const onsiteDays = Number(record.onsite_days ?? 0);
                    const basicDaily = Number(record.rates?.basic_daily ?? 0);
                    const siteDaily = Number(
                        record.rates?.site_allowance_daily ?? 0,
                    );
                    const supplementaryDaily = Number(
                        record.rates?.supplementary_allowance_daily ?? 0,
                    );

                    // standby pay = standby_days × (basic + supplementary)
                    const standbyAmount = Number(record.standby_pay ?? 0);

                    // onsite pay = onsite_days × (basic + supplementary + site)
                    const onsiteAmount =
                        Number(record.onsite_pay ?? 0) +
                        Number(record.site_allowance ?? 0) +
                        Number(record.supplementary_allowance ?? 0);

                    const overtimeHours = Number(record.overtime?.hours ?? record.overtime_hours ?? 0);
                    const overtimeAmount = Number(
                        record.overtime?.overtime_pay ?? record.overtime_pay ?? 0,
                    );

                    return (
                        <TableRow
                            key={record.id}
                            className={cn(
                                dataTableBodyRowClass(false),
                                'group transition-all duration-150',
                                isSelected && '[&>td]:bg-primary/5',
                                !isSelected && 'hover:bg-muted/30',
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
                                salary_payment_method={paymentMethod}
                            />

                            <PayrollRecordPaymentMethodCell
                                method={paymentMethod}
                                label={
                                    record.salary_payment_method_label ??
                                    'Bank transfer'
                                }
                            />

                            {/* Stand By */}
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    'border-l border-primary/8',
                                    !isSelected && 'bg-primary/2',
                                )}
                            >
                                <CrewPayColumnCell
                                    days={standbyDays}
                                    dailyBasic={basicDaily}
                                    dailySupplementary={supplementaryDaily}
                                    totalAmount={standbyAmount}
                                    variant="standby"
                                />
                            </TableCell>

                            {/* On Site */}
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    'border-x border-primary/8',
                                    !isSelected && 'bg-primary/2',
                                )}
                            >
                                <CrewPayColumnCell
                                    days={onsiteDays}
                                    dailyBasic={basicDaily}
                                    dailySupplementary={supplementaryDaily}
                                    dailySite={siteDaily}
                                    totalAmount={onsiteAmount}
                                    variant="onsite"
                                />
                            </TableCell>

                            {/* Overtime */}
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    'border-r border-primary/8',
                                    !isSelected && 'bg-primary/2',
                                )}
                            >
                                <CrewOvertimeColumnCell
                                    hours={overtimeHours}
                                    totalAmount={overtimeAmount}
                                />
                            </TableCell>

                            {/* Salary Inputs */}
                            {showSalaryInputsColumn ? (
                                <PayrollRecordSalaryInputsCell
                                    inputs={
                                        salaryInputsByEmployee[
                                            String(record.employee.id)
                                        ] ?? []
                                    }
                                />
                            ) : null}

                            {/* Gross */}
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    'text-sm tabular-nums',
                                )}
                            >
                                {grossAmount > 0 ? (
                                    <span className="font-medium">
                                        {formatTimesheetAmount(
                                            record.gross_salary,
                                        )}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground/40">
                                        —
                                    </span>
                                )}
                            </TableCell>

                            {/* Net */}
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    'tabular-nums',
                                )}
                            >
                                <span
                                    className={cn(
                                        'text-sm font-bold',
                                        netAmount > 0
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-muted-foreground/50',
                                    )}
                                >
                                    {netAmount > 0
                                        ? formatTimesheetAmount(record.net_salary)
                                        : '—'}
                                </span>
                            </TableCell>

                            {/* Payslip */}
                            <PayrollRecordPayslipStatusCell
                                has_payslip={record.has_payslip}
                                wps_status_label={record.wps_status_label}
                                isLiveUpdating={isPayslipGenerationLive}
                            />

                            {/* Actions */}
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
