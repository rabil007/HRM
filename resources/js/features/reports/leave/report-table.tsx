import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import {
    DataTableHead,
    OrganizationDataTable,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { LeaveRequestStatusBadge } from '@/features/attendance/leave-requests/components/leave-request-status-badge';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { LeaveReportApprovalHistoryDialog } from './approval-history-dialog';
import type { LeaveReportFilters, LeaveReportRow } from './types';

const COLUMN_COUNT = 11;

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

const FALLBACK_LEAVE_COLOR = '#94a3b8';

function LeaveTypeCell({ row }: { row: LeaveReportRow }) {
    if (!row.leave_type) {
        return <>—</>;
    }

    const color = row.leave_type.color ?? FALLBACK_LEAVE_COLOR;

    return (
        <div className="flex items-center gap-2">
            <span
                className="inline-block size-2.5 shrink-0 rounded-full border border-black/10 dark:border-white/10"
                style={{ backgroundColor: color }}
            />
            <Badge
                variant="outline"
                className="max-w-[12rem] truncate text-[10px] font-bold tracking-wider uppercase"
                style={{
                    borderColor: `${color}40`,
                    backgroundColor: `${color}15`,
                    color,
                }}
            >
                {row.leave_type.code || row.leave_type.name}
            </Badge>
            <span className="truncate font-medium">{row.leave_type.name}</span>
        </div>
    );
}

function EmployeeCell({ row }: { row: LeaveReportRow }) {
    const name = row.employee.name ?? '—';
    const employeeNo = row.employee.employee_no;

    const profileContent = (
        <>
            <EmployeeAvatar
                name={name}
                image={row.employee.can_view ? row.employee.image : null}
                size="sm"
                className="shrink-0"
            />
            <div className="min-w-0">
                <span className="block truncate text-sm font-semibold text-foreground group-hover:text-primary">
                    {name}
                </span>
                {employeeNo ? (
                    <span className="block truncate font-mono text-[11px] text-muted-foreground/75">
                        {employeeNo}
                    </span>
                ) : null}
            </div>
        </>
    );

    if (row.employee.can_view && row.employee.id) {
        return (
            <EmployeeProfileLink
                employeeId={row.employee.id}
                className="group flex min-w-0 items-center gap-3 no-underline hover:no-underline"
                aria-label={`View profile for ${name}`}
            >
                {profileContent}
            </EmployeeProfileLink>
        );
    }

    return (
        <div className="flex min-w-0 items-center gap-3">{profileContent}</div>
    );
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
    const [historyRow, setHistoryRow] = useState<LeaveReportRow | null>(null);

    return (
        <>
            <OrganizationDataTable minWidth="min-w-[1320px]" compact>
                <TableHeader>
                    <TableRow>
                        <SortHead
                            column="employee_name"
                            label="Employee"
                            filters={filters}
                            onSort={onSort}
                        />
                        <SortHead
                            label="Department"
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
                            label="Approval"
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
                            <TableCell
                                className={cn(
                                    dataTableCellClass(),
                                    dataTableCellPrimaryClass(),
                                    'min-w-[220px]',
                                )}
                            >
                                <EmployeeCell row={row} />
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {row.department?.name ?? '—'}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <LeaveTypeCell row={row} />
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
                                <button
                                    type="button"
                                    className="text-left text-sm font-medium text-primary underline-offset-2 hover:underline"
                                    onClick={() => setHistoryRow(row)}
                                >
                                    <span className="block">
                                        {row.approval_progress.label}
                                    </span>
                                    {row.approval_progress.waiting_for &&
                                    row.approval_progress.approved_steps > 0 ? (
                                        <span className="block text-xs font-normal text-muted-foreground">
                                            Waiting for{' '}
                                            {row.approval_progress.waiting_for}
                                        </span>
                                    ) : null}
                                </button>
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
            <LeaveReportApprovalHistoryDialog
                row={historyRow}
                open={historyRow !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setHistoryRow(null);
                    }
                }}
            />
        </>
    );
}
