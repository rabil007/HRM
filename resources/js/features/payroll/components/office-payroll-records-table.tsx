import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import {
    PayrollRecordPayslipActionsCell,
    PayrollRecordPayslipStatusCell,
} from './payroll-record-payslip-cells';
import type { OfficePayrollRecordListItem } from '../types';
import { formatTimesheetAmount, formatTimesheetDays } from '../types';

export function OfficePayrollRecordsTable({
    records,
    canViewPayslips,
}: {
    records: OfficePayrollRecordListItem[];
    canViewPayslips: boolean;
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[1320px]">
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead className="pl-5">Employee</DataTableHead>
                    <DataTableHead>Basic</DataTableHead>
                    <DataTableHead>Housing</DataTableHead>
                    <DataTableHead>Transport</DataTableHead>
                    <DataTableHead>Present</DataTableHead>
                    <DataTableHead>OT</DataTableHead>
                    <DataTableHead>Gross</DataTableHead>
                    <DataTableHead>Deductions</DataTableHead>
                    <DataTableHead>Net</DataTableHead>
                    <DataTableHead>Payslip</DataTableHead>
                    <DataTableHead className={dataTableActionsCellClass()}>Actions</DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {records.map((record) => (
                    <TableRow key={record.id} className={dataTableBodyRowClass(false)}>
                        <TableCell className={dataTableCellPrimaryClass()}>
                            <div className="font-semibold">{record.employee.name}</div>
                            <div className="text-xs text-muted-foreground">
                                {record.employee.employee_no ?? '—'}
                            </div>
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
                            <div>
                                {formatTimesheetDays(String(record.present_days ?? ''))} /{' '}
                                {formatTimesheetDays(String(record.working_days ?? ''))}
                            </div>
                            <div className="text-xs text-muted-foreground">days</div>
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <div>{formatTimesheetDays(record.overtime_hours)} hrs</div>
                            <div className="text-xs text-muted-foreground">
                                {formatTimesheetAmount(record.overtime_pay)}
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
                        <PayrollRecordPayslipActionsCell
                            recordId={record.id}
                            canViewPayslips={canViewPayslips}
                        />
                    </TableRow>
                ))}
            </TableBody>
        </OrganizationDataTable>
    );
}
