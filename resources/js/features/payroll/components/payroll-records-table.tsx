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
import type { CrewPayrollRecordListItem } from '../types';
import { formatTimesheetAmount, formatTimesheetDays } from '../types';
import {
    PayrollRecordPayslipActionsCell,
    PayrollRecordPayslipStatusCell,
} from './payroll-record-payslip-cells';

export function PayrollRecordsTable({
    records,
    canViewPayslips,
}: {
    records: CrewPayrollRecordListItem[];
    canViewPayslips: boolean;
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[1220px]">
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead className="pl-5">Employee</DataTableHead>
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
                {records.map((record) => (
                    <TableRow key={record.id} className={dataTableBodyRowClass(false)}>
                        <TableCell className={dataTableCellPrimaryClass()}>
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
