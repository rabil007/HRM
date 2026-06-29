import { Trash2 } from 'lucide-react';
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
import type { CrewPayrollRecordListItem } from '../types';
import { formatTimesheetAmount, formatTimesheetDays } from '../types';
import {
    PayrollRecordPayslipActionButtons,
    PayrollRecordPayslipStatusCell,
} from './payroll-record-payslip-cells';

import {
    PayrollRecordWpsSelectionCell,
    PayrollRecordWpsSelectionHead,
} from './payroll-record-wps-selection';

export function PayrollRecordsTable({
    records,
    canViewPayslips,
    canShowPayslipActions,
    canRemove,
    wpsSelection,
    onRemove,
}: {
    records: CrewPayrollRecordListItem[];
    canViewPayslips: boolean;
    canShowPayslipActions: boolean;
    canRemove: boolean;
    wpsSelection?: {
        selectedRecordIds: number[];
        allSelected: boolean;
        someSelected: boolean;
        onToggleRecord: (recordId: number) => void;
        onToggleAll: () => void;
    };
    onRemove: (record: CrewPayrollRecordListItem) => void;
}) {
    return (
        <OrganizationDataTable minWidth={wpsSelection ? 'min-w-[1260px]' : 'min-w-[1220px]'}>
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
                    <DataTableHead>Standby</DataTableHead>
                    <DataTableHead>Onsite</DataTableHead>
                    <DataTableHead>Gross</DataTableHead>
                    <DataTableHead>Deductions</DataTableHead>
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
                            <div>{formatTimesheetDays(String(record.standby_days ?? ''))} days</div>
                            <div className="text-xs text-muted-foreground">
                                {formatTimesheetAmount(record.standby_pay)}
                            </div>
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <div>{formatTimesheetDays(String(record.onsite_days ?? ''))} days</div>
                            <div className="text-xs text-muted-foreground">
                                {formatTimesheetAmount(record.onsite_pay)}
                            </div>
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.gross_salary)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatTimesheetAmount(record.deduction_amount)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <span className="font-semibold">
                                {formatTimesheetAmount(record.net_salary)}
                            </span>
                        </TableCell>
                        <PayrollRecordPayslipStatusCell
                            has_payslip={record.has_payslip}
                            wps_status_label={record.wps_status_label}
                        />
                        <TableCell className={dataTableActionsCellClass()}>
                            <div className="flex items-center justify-end gap-2">
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
