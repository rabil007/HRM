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
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { cn } from '@/lib/utils';
import type { LeaveBalanceReportRow } from './types';

function days(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function remainingClass(remaining: number, available: number): string {
    if (remaining <= 0) {
        return 'font-semibold text-rose-600 dark:text-rose-400';
    }

    if (available > 0 && remaining / available <= 0.2) {
        return 'font-semibold text-amber-600 dark:text-amber-400';
    }

    return 'font-semibold text-emerald-600 dark:text-emerald-400';
}

function DaysCell({ value, className }: { value: number; className?: string }) {
    return (
        <TableCell
            className={cn(
                dataTableCellClass(),
                'text-right tabular-nums',
                className,
            )}
        >
            {days(value)}
        </TableCell>
    );
}

function categoryBadgeClass(category: string | null): string {
    switch (category) {
        case 'annual':
            return 'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-300';
        case 'sick':
            return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-700 dark:text-cyan-300';
        default:
            return 'border-border/60 bg-muted/50 text-muted-foreground';
    }
}

function EmployeeCell({ row }: { row: LeaveBalanceReportRow }) {
    const name = row.employee.name;
    const departmentName = row.department?.name ?? null;

    const profileContent = (
        <>
            <EmployeeAvatar
                name={name}
                image={row.employee.can_view ? row.employee.image : null}
                size="sm"
                className="shrink-0"
            />
            <div className="min-w-0 space-y-1">
                <span className="block truncate text-sm font-semibold text-foreground group-hover:text-primary">
                    {name}
                </span>
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    {row.employee.employee_no ? (
                        <span className="truncate font-mono text-[11px] text-muted-foreground/80">
                            {row.employee.employee_no}
                        </span>
                    ) : null}
                    {row.employee.status_label ? (
                        <Badge
                            variant="outline"
                            className="h-5 max-w-[8rem] truncate px-1.5 text-[10px] font-medium"
                        >
                            {row.employee.status_label}
                        </Badge>
                    ) : null}
                </div>
                <span className="block truncate text-[11px] text-muted-foreground">
                    {departmentName ?? '—'}
                </span>
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

function LeaveTypeCell({ row }: { row: LeaveBalanceReportRow }) {
    return (
        <div className="min-w-0 space-y-1">
            <span className="block truncate font-medium">
                {row.leave_type.name}
            </span>
            <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                {row.leave_type.code ? (
                    <span className="font-mono text-[11px] text-muted-foreground">
                        {row.leave_type.code}
                    </span>
                ) : null}
                {row.leave_type.category_label ? (
                    <Badge
                        variant="outline"
                        className={cn(
                            'h-5 max-w-[8rem] truncate px-1.5 text-[10px] font-semibold tracking-wide uppercase',
                            categoryBadgeClass(row.leave_type.category),
                        )}
                    >
                        {row.leave_type.category_label}
                    </Badge>
                ) : null}
            </div>
        </div>
    );
}

export function LeaveBalanceReportTable({
    rows,
}: {
    rows: LeaveBalanceReportRow[];
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[1000px]" compact>
            <TableHeader>
                <TableRow>
                    <DataTableHead>Employee</DataTableHead>
                    <DataTableHead>Leave Type</DataTableHead>
                    <DataTableHead className="text-center">Year</DataTableHead>
                    <DataTableHead className="text-right">Base</DataTableHead>
                    <DataTableHead className="text-right">Carry</DataTableHead>
                    <DataTableHead className="text-right">
                        Available
                    </DataTableHead>
                    <DataTableHead className="text-right">Used</DataTableHead>
                    <DataTableHead className="text-right">
                        Pending
                    </DataTableHead>
                    <DataTableHead className="text-right">
                        Remaining
                    </DataTableHead>
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
                            <EmployeeCell row={row} />
                        </TableCell>
                        <TableCell className={dataTableCellClass()}>
                            <LeaveTypeCell row={row} />
                        </TableCell>
                        <TableCell
                            className={cn(dataTableCellClass(), 'text-center')}
                        >
                            <Badge variant="secondary" className="tabular-nums">
                                {row.year}
                            </Badge>
                        </TableCell>
                        <DaysCell value={row.base_entitlement} />
                        <DaysCell value={row.carried_days} />
                        <DaysCell
                            value={row.total_available}
                            className="font-medium"
                        />
                        <DaysCell value={row.used_days} />
                        <DaysCell
                            value={row.pending_days}
                            className={
                                row.pending_days > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : undefined
                            }
                        />
                        <DaysCell
                            value={row.remaining_days}
                            className={remainingClass(
                                row.remaining_days,
                                row.total_available,
                            )}
                        />
                    </TableRow>
                ))}
            </TableBody>
        </OrganizationDataTable>
    );
}
