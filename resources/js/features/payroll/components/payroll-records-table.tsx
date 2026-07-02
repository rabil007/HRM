import { Link } from '@inertiajs/react';
import { CalendarDays, Plus, Trash2 } from 'lucide-react';
import { show as showEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { resolveEmployeeImageUrl } from '@/features/organization/employees/lib/employee-avatar';
import type {SalaryPaymentMethodValue} from '@/features/organization/employees/salary-payment-method';
import { cn } from '@/lib/utils';
import type { CrewPayrollRecordListItem, SalaryInput } from '../types';
import { formatTimesheetAmount, formatTimesheetDays } from '../types';
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
    canViewPayslips,
    canShowPayslipActions,
    canManageSalaryInputs,
    canRemove,
    wpsSelection,
    onManageSalaryInputs,
    onRemove,
}: {
    records: CrewPayrollRecordListItem[];
    salaryInputsByEmployee: Record<string, SalaryInput[]>;
    canViewPayslips: boolean;
    canShowPayslipActions: boolean;
    canManageSalaryInputs: boolean;
    canRemove: boolean;
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

    return (
        <OrganizationDataTable minWidth={wpsSelection ? 'min-w-[1660px]' : 'min-w-[1600px]'}>
            <TableHeader>
                {/* Group labels */}
                <tr className="border-b-0">
                    {wpsSelection ? <th className="h-7 border-b border-border/30" /> : null}
                    <th className="h-7 border-b border-border/30" />
                    <th className="h-7 border-b border-border/30" />
                    <th className="h-7 border-b border-border/30" />
                    <th
                        colSpan={2}
                        className="h-7 border-x border-b border-blue-500/15 bg-blue-500/3 px-3 text-center text-[10px] font-bold uppercase tracking-[0.15em] text-blue-600/60 dark:text-blue-400/60"
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <CalendarDays className="h-3 w-3" />
                            Days worked
                        </span>
                    </th>
                    <th
                        colSpan={3}
                        className="h-7 border-x border-b border-primary/15 bg-primary/3 px-3 text-center text-[10px] font-bold uppercase tracking-[0.15em] text-primary/50"
                    >
                        Rates & Allowances
                    </th>
                    {showSalaryInputsColumn ? <th className="h-7 border-b border-border/30" /> : null}
                    <th colSpan={2} className="h-7 border-b border-border/30" />
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
                    <DataTableHead className={wpsSelection ? undefined : 'pl-5'}>Employee</DataTableHead>
                    <DataTableHead>Bank account</DataTableHead>
                    <DataTableHead>Payment</DataTableHead>
                    <DataTableHead className="border-l border-blue-500/10 bg-blue-500/3">Standby</DataTableHead>
                    <DataTableHead className="border-r border-blue-500/10 bg-blue-500/3">Onsite</DataTableHead>
                    <DataTableHead className="border-l border-primary/10 bg-primary/3">Basic</DataTableHead>
                    <DataTableHead className="bg-primary/3">Site</DataTableHead>
                    <DataTableHead className="border-r border-primary/10 bg-primary/3">Suppl.</DataTableHead>
                    {showSalaryInputsColumn ? <DataTableHead>Salary inputs</DataTableHead> : null}
                    <DataTableHead>Gross</DataTableHead>
                    <DataTableHead>Net</DataTableHead>
                    <DataTableHead>Payslip</DataTableHead>
                    <DataTableHead className={dataTableActionsCellClass()}>Actions</DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {records.map((record) => {
                    const isSelected = wpsSelection?.selectedRecordIds.includes(record.id) ?? false;
                    const paymentMethod = (record.salary_payment_method ?? 'bank_transfer') as SalaryPaymentMethodValue;
                    const netAmount = Number(record.net_salary ?? 0);
                    const grossAmount = Number(record.gross_salary ?? 0);

                    return (
                        <TableRow
                            key={record.id}
                            className={cn(
                                dataTableBodyRowClass(false),
                                'group transition-all duration-150',
                                isSelected && 'bg-primary/5',
                                !isSelected && 'hover:bg-muted/30',
                            )}
                        >
                            {wpsSelection ? (
                                <PayrollRecordWpsSelectionCell
                                    checked={isSelected}
                                    employeeName={record.employee.name}
                                    onToggle={() => wpsSelection.onToggleRecord(record.id)}
                                />
                            ) : null}

                            {/* Employee */}
                            <TableCell className={cn(dataTableCellPrimaryClass(), !wpsSelection && 'pl-5')}>
                                <Link
                                    href={showEmployee.url(record.employee.id)}
                                    className="flex items-center gap-3 group/link"
                                >
                                    <div className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-gradient-to-br from-primary/10 to-primary/25 text-xs font-bold text-primary shadow-sm overflow-hidden transition-all duration-200 group-hover/link:scale-105">
                                        {record.employee.image ? (
                                            <img
                                                src={resolveEmployeeImageUrl(record.employee.image) ?? undefined}
                                                alt=""
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            record.employee.name
                                                .split(' ')
                                                .filter(Boolean)
                                                .slice(0, 2)
                                                .map((part) => part[0]?.toUpperCase())
                                                .join('') || '—'
                                        )}
                                    </div>
                                    <div className="min-w-0">
                                        <span className="block truncate font-semibold leading-tight transition-colors group-hover/link:text-primary">
                                            {record.employee.name}
                                        </span>
                                        <span className="mt-0.5 block font-mono text-[11px] text-muted-foreground/70">
                                            {record.employee.employee_no ?? '—'}
                                        </span>
                                    </div>
                                </Link>
                            </TableCell>

                            {/* Bank account */}
                            <PayrollRecordBankAccountCell
                                primary_account={record.primary_account}
                                salary_payment_method={paymentMethod}
                            />

                            {/* Payment method */}
                            <PayrollRecordPaymentMethodCell
                                method={paymentMethod}
                                label={record.salary_payment_method_label ?? 'Bank transfer'}
                            />

                            {/* Standby */}
                            <TableCell className={cn(dataTableCellClass(), 'border-l border-blue-500/8 bg-blue-500/2')}>
                                <div className="flex flex-col gap-0.5">
                                    <Badge
                                        variant="secondary"
                                        className={cn(
                                            'w-fit inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold tabular-nums',
                                            record.standby_days && record.standby_days > 0
                                                ? 'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                                                : 'border-border/50 bg-transparent text-muted-foreground/50',
                                        )}
                                    >
                                        {record.standby_days && record.standby_days > 0
                                            ? <>{formatTimesheetDays(String(record.standby_days))} days</>
                                            : <>—</>
                                        }
                                    </Badge>
                                    {record.standby_days && record.standby_days > 0 ? (
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {formatTimesheetAmount(record.standby_pay)}
                                        </span>
                                    ) : null}
                                </div>
                            </TableCell>

                            {/* Onsite */}
                            <TableCell className={cn(dataTableCellClass(), 'border-r border-blue-500/8 bg-blue-500/2')}>
                                <div className="flex flex-col gap-0.5">
                                    <Badge
                                        variant="secondary"
                                        className={cn(
                                            'w-fit inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold tabular-nums',
                                            record.onsite_days && record.onsite_days > 0
                                                ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                : 'border-border/50 bg-transparent text-muted-foreground/50',
                                        )}
                                    >
                                        {record.onsite_days && record.onsite_days > 0
                                            ? <>{formatTimesheetDays(String(record.onsite_days))} days</>
                                            : <>—</>
                                        }
                                    </Badge>
                                    {record.onsite_days && record.onsite_days > 0 ? (
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {formatTimesheetAmount(record.onsite_pay)}
                                        </span>
                                    ) : null}
                                </div>
                            </TableCell>

                            {/* Basic salary */}
                            <TableCell className={cn(dataTableCellClass(), 'border-l border-primary/8 bg-primary/2 tabular-nums text-sm')}>
                                <AmountCell value={record.basic_salary} />
                            </TableCell>

                            {/* Site allowance */}
                            <TableCell className={cn(dataTableCellClass(), 'bg-primary/2 tabular-nums text-sm')}>
                                <AmountCell value={record.site_allowance} />
                            </TableCell>

                            {/* Supplementary */}
                            <TableCell className={cn(dataTableCellClass(), 'border-r border-primary/8 bg-primary/2 tabular-nums text-sm')}>
                                <AmountCell value={record.supplementary_allowance} />
                            </TableCell>

                            {/* Salary Inputs */}
                            {showSalaryInputsColumn ? (
                                <PayrollRecordSalaryInputsCell
                                    inputs={salaryInputsByEmployee[String(record.employee.id)] ?? []}
                                />
                            ) : null}

                            {/* Gross */}
                            <TableCell className={cn(dataTableCellClass(), 'tabular-nums text-sm')}>
                                {grossAmount > 0 ? (
                                    <span className="font-medium">{formatTimesheetAmount(record.gross_salary)}</span>
                                ) : (
                                    <span className="text-muted-foreground/40">—</span>
                                )}
                            </TableCell>

                            {/* Net */}
                            <TableCell className={cn(dataTableCellClass(), 'tabular-nums')}>
                                <span
                                    className={cn(
                                        'text-sm font-bold',
                                        netAmount > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground/50',
                                    )}
                                >
                                    {netAmount > 0 ? formatTimesheetAmount(record.net_salary) : '—'}
                                </span>
                            </TableCell>

                            {/* Payslip */}
                            <PayrollRecordPayslipStatusCell
                                has_payslip={record.has_payslip}
                                wps_status_label={record.wps_status_label}
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
                                                    onClick={() => onManageSalaryInputs(record)}
                                                >
                                                    <Plus className="h-4 w-4" />
                                                    {(salaryInputsByEmployee[String(record.employee.id)] ?? []).length > 0 ? (
                                                        <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-none text-primary-foreground">
                                                            {(salaryInputsByEmployee[String(record.employee.id)] ?? []).length}
                                                        </span>
                                                    ) : null}
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Salary inputs</TooltipContent>
                                        </Tooltip>
                                    ) : null}
                                    {canViewPayslips ? (
                                        <PayrollRecordPayslipActionButtons
                                            recordId={record.id}
                                            canView={canShowPayslipActions}
                                            canDownload={canShowPayslipActions}
                                        />
                                    ) : null}
                                    {canRemove ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg text-destructive hover:text-destructive"
                                                    aria-label="Remove from pay run"
                                                    onClick={() => onRemove(record)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Remove from pay run</TooltipContent>
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

function AmountCell({ value }: { value: string | null | undefined }) {
    if (!value || Number(value) === 0) {
        return <span className="text-muted-foreground/40 text-xs">—</span>;
    }

    return <span>{formatTimesheetAmount(value)}</span>;
}
