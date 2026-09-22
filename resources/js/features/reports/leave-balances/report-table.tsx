import {
    OrganizationDataTable,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { DataTableHead } from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { LeaveBalanceReportRow } from './types';

function days(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function LeaveBalanceReportTable({
    rows,
    total,
}: {
    rows: LeaveBalanceReportRow[];
    total: number;
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[1100px]" compact>
            <TableHeader>
                <TableRow>
                    <DataTableHead>Employee</DataTableHead>
                    <DataTableHead>Department</DataTableHead>
                    <DataTableHead>Leave Type</DataTableHead>
                    <DataTableHead>Year</DataTableHead>
                    <DataTableHead>Base</DataTableHead>
                    <DataTableHead>Carry</DataTableHead>
                    <DataTableHead>Available</DataTableHead>
                    <DataTableHead>Used</DataTableHead>
                    <DataTableHead>Pending</DataTableHead>
                    <DataTableHead>Remaining</DataTableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow
                        key={row.id}
                        className={dataTableBodyRowClass(false)}
                    >
                        <TableCell
                            className={`${dataTableCellClass()} ${dataTableCellPrimaryClass()}`}
                        >
                            <span className="block font-semibold">
                                {row.employee.name}
                            </span>
                            <span className="block text-[11px] text-muted-foreground">
                                {[
                                    row.employee.employee_no,
                                    row.employee.status_label,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.department?.name ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <span className="block">{row.leave_type.name}</span>
                            {row.leave_type.category_label ? (
                                <span className="block text-[11px] text-muted-foreground">
                                    {row.leave_type.category_label}
                                </span>
                            ) : null}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.year}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.base_entitlement)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.carried_days)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.total_available)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.used_days)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.pending_days)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {days(row.remaining_days)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
            <tfoot>
                <tr>
                    <td
                        colSpan={10}
                        className="px-4 py-3 text-xs text-muted-foreground"
                    >
                        Showing {rows.length.toLocaleString()} of{' '}
                        {total.toLocaleString()} balance rows
                    </td>
                </tr>
            </tfoot>
        </OrganizationDataTable>
    );
}
