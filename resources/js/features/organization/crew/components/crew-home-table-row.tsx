import { Link, router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { CrewEmployeeIdentity } from '@/features/organization/crew/components/crew-employee-identity';
import type { CurrentCrewHomeRow } from '@/features/organization/crew/types';
import { formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    create as createAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
function availabilityBadgeClass(
    status: CurrentCrewHomeRow['availability_status'],
) {
    switch (status) {
        case 'over_limit':
            return 'border-destructive/30 bg-destructive/10 text-destructive';
        case 'near_limit':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400';
        default:
            return 'border-border bg-muted/40 text-muted-foreground';
    }
}

export function CrewHomeTableRow({ row }: { row: CurrentCrewHomeRow }) {
    const assignmentHref =
        row.latest_assignment && row.can.view_assignment
            ? showAssignment.url(row.latest_assignment.id)
            : null;

    return (
        <TableRow className={dataTableBodyRowClass(false)}>
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[220px]')}
            >
                <CrewEmployeeIdentity employee={row.employee} />
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                {row.rank?.name ?? '—'}
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[160px]')}>
                {row.last_vessel?.name ?? '—'}
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[150px]')}>
                {row.home_since ? formatDisplayDateTime(row.home_since) : '—'}
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[120px]')}>
                {row.days_at_home !== null ? (
                    <span className="font-medium tabular-nums">
                        {row.days_at_home}
                    </span>
                ) : (
                    '—'
                )}
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[200px]')}>
                <div className="space-y-1">
                    <Badge
                        variant="outline"
                        className={availabilityBadgeClass(
                            row.availability_status,
                        )}
                    >
                        {row.availability_label}
                    </Badge>
                    {row.availability_detail ? (
                        <p className="text-[11px] text-muted-foreground">
                            {row.availability_detail}
                        </p>
                    ) : null}
                </div>
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[150px]')}>
                {row.latest_assignment ? (
                    assignmentHref ? (
                        <Link
                            href={assignmentHref}
                            className="font-medium text-primary hover:underline"
                            onClick={(event) => event.stopPropagation()}
                        >
                            {row.latest_assignment.assignment_no}
                        </Link>
                    ) : (
                        <span className="font-medium">
                            {row.latest_assignment.assignment_no}
                        </span>
                    )
                ) : (
                    '—'
                )}
            </TableCell>

            <TableCell
                className={cn(dataTableActionsCellClass(), 'min-w-[160px]')}
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-end gap-2">
                    {assignmentHref ? (
                        <ListTableCrudActions viewHref={assignmentHref} />
                    ) : null}
                    {row.can.start_assignment ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8"
                            onClick={() =>
                                router.visit(
                                    createAssignment.url({
                                        query: {
                                            employee_id: row.employee.id,
                                        },
                                    }),
                                )
                            }
                        >
                            Start
                        </Button>
                    ) : null}
                </div>
            </TableCell>
        </TableRow>
    );
}
