import { Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
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
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewReliefReadinessBadge } from '@/features/organization/crew/components/crew-relief-readiness-badge';
import { CrewTourProgressDisplay } from '@/features/organization/crew/components/crew-tour-progress-display';
import { formatDaysInPhase } from '@/features/organization/crew/format-days-in-phase';
import { reliefActionHref } from '@/features/organization/crew/lib/relief-action';
import type {
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
} from '@/features/organization/crew/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewAssignmentsTableRow({
    assignment,
    viewHref,
    editHref,
    canUpdate,
    canPerformMovement,
    canCancel,
    formOptions,
}: {
    assignment: CrewAssignmentListItem;
    viewHref: string;
    editHref?: string;
    canUpdate: boolean;
    canPerformMovement: boolean;
    canCancel: boolean;
    formOptions?: CrewAssignmentFormOptions;
}) {
    const warningCount = assignment.warnings.length;
    const showMovementActions =
        (canPerformMovement || canCancel) &&
        assignment.available_actions.length > 0;
    const isOnVessel = assignment.current_phase?.code === 'p4';
    const reliefHref = isOnVessel ? reliefActionHref(assignment) : null;
    const reliefActionLabel =
        assignment.relief_action_label ??
        (assignment.relief_status === 'no_relief'
            ? 'Plan Relief'
            : assignment.relief_status === 'relief_planned'
              ? 'Open Relief Plan'
              : 'Open Relief Assignment');

    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(viewHref)}
        >
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[160px]')}
            >
                <div className="min-w-0">
                    <p className="truncate font-semibold text-foreground">
                        {assignment.assignment_no}
                    </p>
                    <p className="truncate text-[11px] text-muted-foreground/70">
                        Created {formatDisplayDate(assignment.created_at)}
                    </p>
                </div>
            </TableCell>

            <TableCell className={cn(dataTableCellClass(), 'min-w-[200px]')}>
                {assignment.employee ? (
                    <div className="flex min-w-0 items-center gap-3">
                        <EmployeeProfileLink
                            employeeId={assignment.employee.id}
                            stopRowNavigation
                            className="shrink-0"
                        >
                            <EmployeeAvatar
                                name={assignment.employee.name}
                                size="sm"
                            />
                        </EmployeeProfileLink>
                        <div className="min-w-0">
                            <EmployeeProfileLink
                                employeeId={assignment.employee.id}
                                className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                                stopRowNavigation
                            >
                                {assignment.employee.name}
                            </EmployeeProfileLink>
                            {assignment.employee.employee_no ? (
                                <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                                    {assignment.employee.employee_no}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                <span className="font-medium text-foreground">
                    {assignment.vessel?.name ?? '—'}
                </span>
                {assignment.client?.name ? (
                    <p className="truncate text-[11px] text-muted-foreground/60">
                        {assignment.client.name}
                    </p>
                ) : null}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {assignment.rank?.name ?? '—'}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {assignment.current_phase ? (
                    <CrewPhaseBadge
                        code={assignment.current_phase.code}
                        label={assignment.current_phase.label}
                        status={assignment.current_phase.status}
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
                {assignment.days_in_phase !== null ? (
                    <p className="mt-1 text-[11px] text-muted-foreground/70">
                        {formatDaysInPhase(assignment.days_in_phase)}
                    </p>
                ) : null}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                <div className="flex flex-col gap-1">
                    <div className="text-[11px] text-muted-foreground/70">
                        Planned join
                    </div>
                    <div className="font-medium">
                        {formatDisplayDate(assignment.planned_join_at)}
                    </div>
                    {isOnVessel ? (
                        <div className="mt-2">
                            <CrewTourProgressDisplay
                                progress={assignment}
                                plannedSignoffAt={assignment.planned_signoff_at}
                                compact
                            />
                        </div>
                    ) : (
                        <>
                            <div className="text-[11px] text-muted-foreground/70">
                                Planned sign-off
                            </div>
                            <div className="font-medium">
                                {formatDisplayDate(
                                    assignment.planned_signoff_at,
                                )}
                            </div>
                        </>
                    )}
                </div>
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {isOnVessel ? (
                    <CrewReliefReadinessBadge
                        relief_status={assignment.relief_status}
                        relief_status_label={assignment.relief_status_label}
                        relief_risk={assignment.relief_risk}
                        relief_risk_label={assignment.relief_risk_label}
                        relief_employee={assignment.relief_employee}
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                <div className="flex flex-wrap items-center gap-1.5">
                    <Badge
                        variant={
                            assignment.status === 'active'
                                ? 'success'
                                : assignment.status === 'draft'
                                  ? 'secondary'
                                  : assignment.status === 'cancelled'
                                    ? 'destructive'
                                    : 'outline'
                        }
                    >
                        {assignment.status_label}
                    </Badge>
                    {warningCount > 0 ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300"
                            title={assignment.warnings
                                .map((warning) => warning.label)
                                .join(', ')}
                        >
                            <AlertTriangle className="size-3" aria-hidden />
                            {warningCount}
                        </Badge>
                    ) : null}
                </div>
            </TableCell>

            <TableCell
                className={dataTableActionsCellClass()}
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-end gap-1.5">
                    {reliefHref ? (
                        <Button
                            asChild
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg px-2.5 text-xs"
                        >
                            <Link
                                href={reliefHref}
                                onClick={(event) => event.stopPropagation()}
                            >
                                {reliefActionLabel}
                            </Link>
                        </Button>
                    ) : null}
                    {showMovementActions ? (
                        <MovementActionMenu
                            assignmentId={assignment.id}
                            availableActions={assignment.available_actions}
                            movementContext={assignment.movement_context}
                            formOptions={formOptions}
                            size="sm"
                        />
                    ) : null}
                    <ListTableCrudActions
                        viewHref={viewHref}
                        onEdit={
                            canUpdate && editHref
                                ? () => router.visit(editHref)
                                : undefined
                        }
                        showEdit={canUpdate && Boolean(editHref)}
                        showDelete={false}
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}
