import { Link } from '@inertiajs/react';
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
import { formatDisplayDate } from '@/lib/format-date';
import type { CrewReliefRow } from './types';

const COLUMN_COUNT = 14;

export function CrewReliefReportTable({ rows }: { rows: CrewReliefRow[] }) {
    return (
        <OrganizationDataTable minWidth="min-w-[1700px]" compact>
            <TableHeader>
                <TableRow>
                    <DataTableHead>Current Crew</DataTableHead>
                    <DataTableHead>Rank</DataTableHead>
                    <DataTableHead>Vessel</DataTableHead>
                    <DataTableHead>Client</DataTableHead>
                    <DataTableHead>Joined</DataTableHead>
                    <DataTableHead>Days Onboard</DataTableHead>
                    <DataTableHead>Planned Sign-Off</DataTableHead>
                    <DataTableHead>Days to Sign-Off</DataTableHead>
                    <DataTableHead>Relief Crew</DataTableHead>
                    <DataTableHead>Relief Status</DataTableHead>
                    <DataTableHead>Relief Planned Join</DataTableHead>
                    <DataTableHead>Readiness</DataTableHead>
                    <DataTableHead>Next Assignment</DataTableHead>
                    <DataTableHead className="sticky right-0 z-20 bg-background shadow-[-4px_0_8px_-2px_rgba(0,0,0,0.05)]">
                        Attention
                    </DataTableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 ? (
                    <TableRow>
                        <TableCell
                            colSpan={COLUMN_COUNT}
                            className="h-32 text-center text-sm text-muted-foreground"
                        >
                            No crew relief records match the selected filters.
                        </TableCell>
                    </TableRow>
                ) : (
                    rows.map((row) => (
                        <TableRow
                            key={row.id}
                            className={dataTableBodyRowClass()}
                        >
                            {/* Current Crew */}
                            <TableCell className={dataTableCellPrimaryClass()}>
                                <div className="flex items-center gap-3">
                                    {row.employee ? (
                                        <EmployeeAvatar
                                            name={row.employee.name}
                                            image={row.employee.photo_url}
                                            size="sm"
                                        />
                                    ) : (
                                        <div className="size-10 rounded-xl bg-muted" />
                                    )}
                                    <div className="min-w-0">
                                        {row.employee?.href ? (
                                            <Link
                                                href={row.employee.href}
                                                className="truncate font-medium text-foreground hover:text-primary hover:underline"
                                            >
                                                {row.employee.name}
                                            </Link>
                                        ) : (
                                            <span className="truncate font-medium text-foreground">
                                                {row.employee?.name ?? '—'}
                                            </span>
                                        )}
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            {row.employee?.employee_no && (
                                                <span>
                                                    {row.employee.employee_no}
                                                </span>
                                            )}
                                            {row.employee?.employee_no &&
                                                row.source_href && (
                                                    <span>•</span>
                                                )}
                                            {row.source_href ? (
                                                <Link
                                                    href={row.source_href}
                                                    className="font-mono text-primary/80 hover:underline"
                                                >
                                                    {row.assignment_no}
                                                </Link>
                                            ) : (
                                                <span className="font-mono">
                                                    {row.assignment_no}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </TableCell>

                            {/* Rank */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="font-medium">
                                    {row.rank?.name ?? '—'}
                                </span>
                            </TableCell>

                            {/* Vessel */}
                            <TableCell className={dataTableCellClass()}>
                                {row.vessel?.href ? (
                                    <Link
                                        href={row.vessel.href}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {row.vessel.name}
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {row.vessel?.name ?? '—'}
                                    </span>
                                )}
                            </TableCell>

                            {/* Client */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="text-muted-foreground">
                                    {row.client?.name ?? '—'}
                                </span>
                            </TableCell>

                            {/* Joined */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="font-medium whitespace-nowrap">
                                    {row.joined_date
                                        ? formatDisplayDate(row.joined_date)
                                        : '—'}
                                </span>
                            </TableCell>

                            {/* Days Onboard */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="font-mono">
                                    {row.days_onboard !== null
                                        ? `${row.days_onboard} d`
                                        : '—'}
                                </span>
                            </TableCell>

                            {/* Planned Sign-Off */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="font-medium whitespace-nowrap">
                                    {row.planned_signoff_at
                                        ? formatDisplayDate(
                                              row.planned_signoff_at,
                                          )
                                        : '—'}
                                </span>
                            </TableCell>

                            {/* Days to Sign-Off */}
                            <TableCell className={dataTableCellClass()}>
                                {row.days_to_signoff !== null &&
                                row.days_to_signoff < 0 ? (
                                    <span className="font-semibold whitespace-nowrap text-destructive">
                                        {row.days_to_signoff_label}
                                    </span>
                                ) : row.days_to_signoff === 0 ? (
                                    <span className="font-semibold whitespace-nowrap text-amber-600 dark:text-amber-400">
                                        {row.days_to_signoff_label}
                                    </span>
                                ) : row.days_to_signoff !== null &&
                                  row.days_to_signoff <= 7 ? (
                                    <span className="font-medium whitespace-nowrap text-amber-600 dark:text-amber-400">
                                        {row.days_to_signoff_label}
                                    </span>
                                ) : (
                                    <span className="whitespace-nowrap text-muted-foreground">
                                        {row.days_to_signoff_label ?? '—'}
                                    </span>
                                )}
                            </TableCell>

                            {/* Relief Crew */}
                            <TableCell className={dataTableCellClass()}>
                                {row.relief_employee ? (
                                    <div>
                                        {row.relief_employee.href ? (
                                            <Link
                                                href={row.relief_employee.href}
                                                className="font-medium text-foreground hover:text-primary hover:underline"
                                            >
                                                {row.relief_employee.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium text-foreground">
                                                {row.relief_employee.name}
                                            </span>
                                        )}
                                        {row.relief_employee.employee_no && (
                                            <div className="text-xs text-muted-foreground">
                                                {
                                                    row.relief_employee
                                                        .employee_no
                                                }
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground italic">
                                        Not Assigned
                                    </span>
                                )}
                            </TableCell>

                            {/* Relief Status */}
                            <TableCell className={dataTableCellClass()}>
                                {row.relief_status !== '—' ? (
                                    <Badge
                                        variant="outline"
                                        className="font-mono text-[11px] whitespace-nowrap"
                                    >
                                        {row.relief_status}
                                    </Badge>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>

                            {/* Relief Planned Join */}
                            <TableCell className={dataTableCellClass()}>
                                <span className="whitespace-nowrap">
                                    {row.relief_planned_join
                                        ? formatDisplayDate(
                                              row.relief_planned_join,
                                          )
                                        : '—'}
                                </span>
                            </TableCell>

                            {/* Readiness */}
                            <TableCell className={dataTableCellClass()}>
                                {row.readiness === 'ready' && (
                                    <Badge className="border-emerald-500/30 bg-emerald-500/15 whitespace-nowrap text-emerald-700 dark:text-emerald-400">
                                        Ready
                                    </Badge>
                                )}
                                {row.readiness === 'joined' && (
                                    <Badge className="border-teal-500/30 bg-teal-500/15 whitespace-nowrap text-teal-700 dark:text-teal-400">
                                        Joined
                                    </Badge>
                                )}
                                {row.readiness === 'in_progress' && (
                                    <Badge className="border-blue-500/30 bg-blue-500/15 whitespace-nowrap text-blue-700 dark:text-blue-400">
                                        In Progress
                                    </Badge>
                                )}
                                {row.readiness === 'at_risk' && (
                                    <Badge className="border-rose-500/30 bg-rose-500/15 whitespace-nowrap text-rose-700 dark:text-rose-400">
                                        At Risk
                                    </Badge>
                                )}
                                {row.readiness === 'not_assigned' && (
                                    <Badge
                                        variant="outline"
                                        className="whitespace-nowrap text-muted-foreground"
                                    >
                                        Not Assigned
                                    </Badge>
                                )}
                            </TableCell>

                            {/* Next Assignment */}
                            <TableCell className={dataTableCellClass()}>
                                {row.next_assignment ? (
                                    <div className="flex items-center gap-1.5 whitespace-nowrap">
                                        {row.next_assignment.href ? (
                                            <Link
                                                href={row.next_assignment.href}
                                                className="font-mono text-xs font-medium text-primary hover:underline"
                                            >
                                                {
                                                    row.next_assignment
                                                        .assignment_no
                                                }
                                            </Link>
                                        ) : (
                                            <span className="font-mono text-xs font-medium">
                                                {
                                                    row.next_assignment
                                                        .assignment_no
                                                }
                                            </span>
                                        )}
                                        <Badge
                                            variant="secondary"
                                            className="px-1 py-0 text-[10px]"
                                        >
                                            {row.next_assignment.status_label}
                                        </Badge>
                                    </div>
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        None
                                    </span>
                                )}
                            </TableCell>

                            {/* Attention */}
                            <TableCell className="sticky right-0 z-10 bg-background shadow-[-4px_0_8px_-2px_rgba(0,0,0,0.05)]">
                                {row.attention.level === 'critical' ? (
                                    <Badge
                                        variant="destructive"
                                        className="text-xs font-semibold whitespace-nowrap"
                                    >
                                        {row.attention.badge}
                                    </Badge>
                                ) : row.attention.level === 'warning' ? (
                                    <Badge className="border-amber-500/30 bg-amber-500/15 text-xs font-semibold whitespace-nowrap text-amber-700 dark:text-amber-400">
                                        {row.attention.badge}
                                    </Badge>
                                ) : row.attention.level === 'healthy' ? (
                                    <Badge className="border-emerald-500/30 bg-emerald-500/15 text-xs font-semibold whitespace-nowrap text-emerald-700 dark:text-emerald-400">
                                        {row.attention.badge}
                                    </Badge>
                                ) : (
                                    <Badge
                                        variant="secondary"
                                        className="text-xs font-medium whitespace-nowrap"
                                    >
                                        {row.attention.badge}
                                    </Badge>
                                )}
                            </TableCell>
                        </TableRow>
                    ))
                )}
            </TableBody>
        </OrganizationDataTable>
    );
}
