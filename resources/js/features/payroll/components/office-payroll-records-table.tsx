import { Plus, Trash2 } from 'lucide-react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { OfficePayrollRecordListItem, SalaryInput } from '../types';
import { formatTimesheetAmount } from '../types';
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
    canViewPayslips,
    canShowPayslipActions,
    canManageSalaryInputs,
    canRemove,
    wpsSelection,
    onManageSalaryInputs,
    onRemove,
}: {
    records: OfficePayrollRecordListItem[];
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
                    <DataTableHead className={wpsSelection ? undefined : 'pl-5'}>Employee</DataTableHead>
                    <DataTableHead>Bank</DataTableHead>
                    <DataTableHead>IBAN</DataTableHead>
                    <DataTableHead>Basic</DataTableHead>
                    <DataTableHead>Housing</DataTableHead>
                    <DataTableHead>Transport</DataTableHead>
                    <DataTableHead>Other</DataTableHead>
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

                    return (
                    <TableRow
                        key={record.id}
                        className={cn(dataTableBodyRowClass(false), isSelected && 'bg-primary/5')}
                    >
                        {wpsSelection ? (
                            <PayrollRecordWpsSelectionCell
                                checked={isSelected}
                                employeeName={record.employee.name}
                                onToggle={() => wpsSelection.onToggleRecord(record.id)}
                            />
                        ) : null}
                        <TableCell className={cn(dataTableCellPrimaryClass(), !wpsSelection && 'pl-5')}>
                            <div className="font-semibold">{record.employee.name}</div>
                            <div className="text-xs text-muted-foreground">
                                {record.employee.employee_no ?? '—'}
                            </div>
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {record.primary_account?.bank_name ?? '—'}
                        </TableCell>
                        <TableCell className={cn(dataTableCellClass(), 'font-mono text-xs')}>
                            {record.primary_account?.iban ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.basic_salary)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.housing_allowance)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.transport_allowance)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.other_allowances)}
                        </TableCell>
                        {showSalaryInputsColumn ? (
                            <PayrollRecordSalaryInputsCell
                                inputs={salaryInputsByEmployee[String(record.employee.id)] ?? []}
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
                            salary_payment_method={record.salary_payment_method}
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
                                                onClick={() => onManageSalaryInputs(record)}
                                            >
                                                <Plus className="h-4 w-4" />
                                                {record.salary_inputs_count > 0 ? (
                                                    <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-none text-primary-foreground">
                                                        {record.salary_inputs_count}
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
