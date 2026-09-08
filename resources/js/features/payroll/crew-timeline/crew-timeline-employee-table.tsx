import { Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { CrewTimelineLinesDialog } from './crew-timeline-lines-dialog';
import type { CrewTimelineEmployeeSummary } from './types';

type WarningDetail = {
    label: string;
    remarks: string | null;
    from: string | null;
    to: string | null;
    is_blocking: boolean;
};

function PhaseRange({
    from,
    to,
    days,
}: {
    from: string | null;
    to: string | null;
    days: number;
}) {
    if (!from && !to && days <= 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="space-y-1">
            <div className="flex items-center gap-1.5 text-xs">
                <span className="font-medium">{formatDisplayDate(from)}</span>
                <span className="text-muted-foreground">→</span>
                <span className="font-medium">{formatDisplayDate(to)}</span>
            </div>
            <Badge
                variant="outline"
                className="rounded-md border-border/60 bg-muted/40 px-1.5 py-0 text-[10px] font-semibold text-muted-foreground tabular-nums"
            >
                {days.toFixed(2)} days
            </Badge>
        </div>
    );
}

function WarningCell({ items }: { items: WarningDetail[] }) {
    if (items.length === 0) {
        return <span className="text-muted-foreground tabular-nums">—</span>;
    }

    const sortedItems = [...items].sort((a, b) => {
        if (a.is_blocking === b.is_blocking) {
            return 0;
        }

        return a.is_blocking ? -1 : 1;
    });

    return (
        <div className="flex flex-col gap-1">
            {sortedItems.map((item, index) => {
                const range =
                    item.from || item.to
                        ? `${formatDisplayDate(item.from)} → ${formatDisplayDate(item.to)}`
                        : null;

                const toneClass = item.is_blocking
                    ? 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300'
                    : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';

                return (
                    <Tooltip key={`${item.label}-${index}`}>
                        <TooltipTrigger asChild>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'w-fit cursor-help rounded-md px-1.5 py-0 text-[10px] font-medium',
                                    toneClass,
                                )}
                            >
                                {item.label}
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                            <p className="font-semibold">{item.label}</p>
                            <p className="text-xs font-medium text-muted-foreground">
                                {item.is_blocking
                                    ? 'Blocking warning'
                                    : 'Informational warning'}
                            </p>
                            {range ? (
                                <p className="mt-0.5 opacity-80">{range}</p>
                            ) : null}
                            {item.remarks ? (
                                <p className="mt-1 opacity-80">
                                    {item.remarks}
                                </p>
                            ) : null}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

function warningDetails(
    employee: CrewTimelineEmployeeSummary,
): WarningDetail[] {
    return employee.lines
        .filter((line) => line.warning !== null)
        .map((line) => ({
            label: line.warning!.label,
            remarks: line.remarks,
            from: line.from_date,
            to: line.to_date,
            is_blocking: line.warning!.is_blocking,
        }));
}

function EmployeeCell({ employee }: { employee: CrewTimelineEmployeeSummary }) {
    const isMultiAssignment = (employee.assignment_count ?? 1) > 1;
    const assignments = employee.assignments ?? [];

    const singleAssignmentId =
        employee.assignment_id ?? assignments[0]?.id ?? null;
    const singleAssignmentNumber =
        employee.assignment_number ??
        assignments[0]?.assignment_number ??
        (singleAssignmentId ? `Assignment #${singleAssignmentId}` : null);

    return (
        <div className="flex items-center gap-3">
            <EmployeeAvatar
                name={employee.employee_name ?? ''}
                image={employee.employee_image}
                size="sm"
                className="shrink-0 rounded-lg"
            />
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    {employee.blocking_warning_count > 0 ? (
                        <span className="size-1.5 shrink-0 rounded-full bg-red-500" />
                    ) : employee.informational_warning_count > 0 ? (
                        <span className="size-1.5 shrink-0 rounded-full bg-amber-400" />
                    ) : null}
                    <span className="truncate font-medium">
                        {employee.employee_name ?? '—'}
                    </span>
                </div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted-foreground">
                    <span>{employee.employee_number ?? '—'}</span>
                    {employee.rank ? (
                        <>
                            <span className="text-border">·</span>
                            <span className="font-medium text-foreground/70">
                                {employee.rank}
                            </span>
                        </>
                    ) : null}
                    {isMultiAssignment && assignments.length > 0 ? (
                        <>
                            <span className="text-border">·</span>
                            <span className="inline-flex flex-wrap items-center gap-1">
                                {assignments.map((assignment, index) => {
                                    const number =
                                        assignment.assignment_number ??
                                        (assignment.id
                                            ? `Assignment #${assignment.id}`
                                            : '—');

                                    return (
                                        <span
                                            key={
                                                assignment.id ??
                                                `${employee.employee_id}-${index}`
                                            }
                                            className="inline-flex items-center gap-1"
                                        >
                                            {index > 0 && (
                                                <span className="text-muted-foreground/60">
                                                    ·
                                                </span>
                                            )}
                                            {assignment.id ? (
                                                <Link
                                                    href={showAssignment.url(
                                                        assignment.id,
                                                    )}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {number}
                                                </Link>
                                            ) : (
                                                <span>{number}</span>
                                            )}
                                        </span>
                                    );
                                })}
                            </span>
                        </>
                    ) : singleAssignmentNumber ? (
                        <>
                            <span className="text-border">·</span>
                            {singleAssignmentId ? (
                                <Link
                                    href={showAssignment.url(
                                        singleAssignmentId,
                                    )}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {singleAssignmentNumber}
                                </Link>
                            ) : (
                                <span>{singleAssignmentNumber}</span>
                            )}
                        </>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export function CrewTimelineEmployeeTable({
    employees,
}: {
    employees: CrewTimelineEmployeeSummary[];
}) {
    const [selected, setSelected] =
        useState<CrewTimelineEmployeeSummary | null>(null);

    return (
        <>
            <OrganizationDataTable minWidth="min-w-[1280px]" compact>
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Vessel</DataTableHead>
                        <DataTableHead>Sign-On Standby</DataTableHead>
                        <DataTableHead>Onsite</DataTableHead>
                        <DataTableHead>Sign-Off Standby</DataTableHead>
                        <DataTableHead>Payable days</DataTableHead>
                        <DataTableHead>Warnings</DataTableHead>
                        <DataTableHead className="text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {employees.map((employee) => (
                        <TableRow
                            key={employee.employee_id}
                            className={dataTableBodyRowClass(false)}
                        >
                            <TableCell className={dataTableCellClass()}>
                                <EmployeeCell employee={employee} />
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {(employee.assignment_count ?? 1) > 1 ? (
                                    <div className="space-y-0.5 text-xs">
                                        {(employee.assignments ?? [])
                                            .map(
                                                (assignment) =>
                                                    assignment.vessel,
                                            )
                                            .filter(Boolean)
                                            .join(' → ') || '—'}
                                    </div>
                                ) : (
                                    (employee.vessel ??
                                    employee.assignments?.[0]?.vessel ??
                                    '—')
                                )}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <PhaseRange
                                    from={employee.sign_on_standby_from}
                                    to={employee.sign_on_standby_to}
                                    days={employee.sign_on_standby_days}
                                />
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <PhaseRange
                                    from={employee.onsite_from}
                                    to={employee.onsite_to}
                                    days={employee.onsite_days}
                                />
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <PhaseRange
                                    from={employee.sign_off_standby_from}
                                    to={employee.sign_off_standby_to}
                                    days={employee.sign_off_standby_days}
                                />
                            </TableCell>
                            <TableCell
                                className={`${dataTableCellClass()} tabular-nums`}
                            >
                                <span
                                    className={cn(
                                        'inline-flex items-center rounded-md px-2 py-0.5 text-sm font-bold tabular-nums',
                                        employee.blocking_warning_count > 0
                                            ? 'bg-red-500/10 text-red-700 dark:text-red-300'
                                            : 'bg-primary/8 text-primary',
                                    )}
                                >
                                    {employee.total_payable_days.toFixed(2)}
                                </span>
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <WarningCell items={warningDetails(employee)} />
                            </TableCell>
                            <TableCell className={dataTableActionsCellClass()}>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSelected(employee)}
                                >
                                    View Details
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </OrganizationDataTable>
            <CrewTimelineLinesDialog
                employee={selected}
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                    }
                }}
            />
        </>
    );
}
