import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import {
    DataTableHead,
    OrganizationDataTable,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { LeaveRequestStatusBadge } from '@/features/attendance/leave-requests/components/leave-request-status-badge';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showEmployee } from '@/routes/organization/employees';
import type { LeaveReportFilters, LeaveReportRow } from './types';

const COLUMN_COUNT = 12;

function SortHead({
    column,
    label,
    filters,
    onSort,
    className,
}: {
    column?: string;
    label: string;
    filters: LeaveReportFilters;
    onSort: (column: string) => void;
    className?: string;
}) {
    if (!column) {
        return (
            <DataTableHead className={cn('whitespace-nowrap', className)}>
                {label}
            </DataTableHead>
        );
    }

    const active = filters.sort === column;

    return (
        <DataTableHead className={cn('whitespace-nowrap', className)}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className="inline-flex items-center gap-1 font-semibold hover:text-foreground"
            >
                {label}
                {active ? (
                    filters.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </DataTableHead>
    );
}

function EmployeeCell({ row }: { row: LeaveReportRow }) {
    if (row.employee.can_view && row.employee.id) {
        return (
            <Link
                href={showEmployee.url(row.employee.id)}
                className="font-medium text-primary hover:underline"
            >
                {row.employee.name}
            </Link>
        );
    }

    return <span>{row.employee.name ?? '—'}</span>;
}

export function LeaveReportTable({
    rows,
    total,
    filters,
    onSort,
}: {
    rows: LeaveReportRow[];
    total: number;
    filters: LeaveReportFilters;
    onSort: (column: string) => void;
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[1200px]" compact>
            <TableHeader>
                <TableRow>
                    <SortHead
                        column="employee_name"
                        label="Employee No"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="employee_name"
                        label="Employee Name"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        label="Department"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        label="Branch"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="leave_type"
                        label="Leave Type"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="start_date"
                        label="Start Date"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="end_date"
                        label="End Date"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="total_days"
                        label="Total Days"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="status"
                        label="Status"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="created_at"
                        label="Submitted At"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        column="decided_at"
                        label="Decided At"
                        filters={filters}
                        onSort={onSort}
                    />
                    <SortHead
                        label="Decided By"
                        filters={filters}
                        onSort={onSort}
                    />
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow
                        key={row.id}
                        className={dataTableBodyRowClass(false)}
                    >
                        <TableCell className={dataTableCellClass()}>
                            {row.employee.employee_no ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <EmployeeCell row={row} />
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.department?.name ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.branch?.name ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.leave_type?.name ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatDisplayDate(row.start_date)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatDisplayDate(row.end_date)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.total_days ?? '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <LeaveRequestStatusBadge status={row.status} />
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {formatDisplayDateTime(row.submitted_at)}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.decided_at
                                ? formatDisplayDateTime(row.decided_at)
                                : '—'}
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            {row.decided_by ?? '—'}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
            <tfoot>
                <tr>
                    <td
                        colSpan={COLUMN_COUNT}
                        className="px-4 py-3 text-xs text-muted-foreground"
                    >
                        Showing {rows.length.toLocaleString()} of{' '}
                        {total.toLocaleString()} leave requests
                    </td>
                </tr>
            </tfoot>
        </OrganizationDataTable>
    );
}
