import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { PayrollRecordListItem } from '../types';
import { formatTimesheetAmount, formatTimesheetDays } from '../types';

export function PayrollRecordsTable({ records }: { records: PayrollRecordListItem[] }) {
    return (
        <OrganizationDataTable minWidth="min-w-[1100px]">
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead className="pl-5">Employee</DataTableHead>
                    <DataTableHead>Standby</DataTableHead>
                    <DataTableHead>Onsite</DataTableHead>
                    <DataTableHead>Gross</DataTableHead>
                    <DataTableHead>Deductions</DataTableHead>
                    <DataTableHead>Net</DataTableHead>
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
                    </TableRow>
                ))}
            </TableBody>
        </OrganizationDataTable>
    );
}
