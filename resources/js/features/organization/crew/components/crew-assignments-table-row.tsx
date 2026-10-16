import { Link, router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewEmployeeIdentity } from '@/features/organization/crew/components/crew-employee-identity';
import {
    CrewKeyOperationalDateFact,
    CrewOperatorAttentionFact,
    CrewOperatorStageFact,
} from '@/features/organization/crew/components/crew-operator-facts';
import { reliefActionHref } from '@/features/organization/crew/lib/relief-action';
import { CREW_MOVEMENT_ACTION_LABELS } from '@/features/organization/crew/types';
import type {
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
    CrewMovementAction,
} from '@/features/organization/crew/types';
import { RecordSelectionCell } from '@/features/organization/shared/record-selection-checkbox';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

function highlightedMovement(
    assignment: CrewAssignmentListItem,
    canPerformMovement: boolean,
    canCancel: boolean,
): CrewMovementAction | null {
    const recommended = assignment.recommended_action;

    if (recommended?.type !== 'movement' || !recommended.action) {
        return null;
    }

    if (
        !(recommended.action in CREW_MOVEMENT_ACTION_LABELS) ||
        !assignment.available_actions.includes(recommended.action)
    ) {
        return null;
    }

    const action = recommended.action as CrewMovementAction;
    const permitted =
        action === 'cancel_assignment' ? canCancel : canPerformMovement;

    return permitted ? action : null;
}

export function CrewAssignmentsTableRow({
    assignment,
    viewHref,
    editHref,
    canUpdate,
    canPerformMovement,
    canCancel,
    canVoid,
    formOptions,
    onView,
    selected,
    onToggleSelect,
    showSelect,
    onDelete,
}: {
    assignment: CrewAssignmentListItem;
    viewHref: string;
    editHref?: string;
    canUpdate: boolean;
    canPerformMovement: boolean;
    canCancel: boolean;
    canVoid?: boolean;
    formOptions?: CrewAssignmentFormOptions;
    onView?: () => void;
    selected?: boolean;
    onToggleSelect?: () => void;
    showSelect?: boolean;
    onDelete?: () => void;
}) {
    const showMovementActions =
        (canPerformMovement || canCancel) &&
        assignment.available_actions.length > 0;
    const isOnVessel = assignment.current_phase?.code === 'p2';
    const primaryMovement = highlightedMovement(
        assignment,
        canPerformMovement,
        canCancel,
    );
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
            className={cn(
                dataTableBodyRowClass(Boolean(selected)),
                'cursor-pointer',
            )}
            onClick={() => (onView ? onView() : router.visit(viewHref))}
        >
            {showSelect ? (
                <RecordSelectionCell
                    checked={Boolean(selected)}
                    onToggle={onToggleSelect ?? (() => {})}
                    label={`Select ${assignment.assignment_no}`}
                />
            ) : null}
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[240px]')}
            >
                <CrewEmployeeIdentity
                    employee={assignment.employee}
                    positionName={assignment.position?.name}
                />
                <p className="mt-1 truncate text-[11px] text-muted-foreground/70">
                    {assignment.assignment_no}
                    {assignment.created_at
                        ? ` · Created ${formatDisplayDate(assignment.created_at)}`
                        : ''}
                </p>
            </TableCell>

            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'w-[160px] max-w-[160px] overflow-hidden',
                )}
            >
                <span
                    className="block truncate font-medium text-foreground"
                    title={assignment.vessel?.name ?? undefined}
                >
                    {assignment.vessel?.name ?? '—'}
                </span>
                {assignment.client?.name ? (
                    <p className="truncate text-[11px] text-muted-foreground/60">
                        {assignment.client.name}
                    </p>
                ) : null}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {assignment.operator_stage ? (
                    <CrewOperatorStageFact stage={assignment.operator_stage} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {assignment.key_operational_date ? (
                    <CrewKeyOperationalDateFact
                        value={assignment.key_operational_date}
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>

            <TableCell className={dataTableCellClass()}>
                {assignment.operator_attention ? (
                    <CrewOperatorAttentionFact
                        attention={assignment.operator_attention}
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
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
                            highlightedAction={primaryMovement}
                        />
                    ) : null}
                    <ListTableCrudActions
                        viewHref={onView ? undefined : viewHref}
                        onView={onView}
                        onEdit={
                            canUpdate && editHref
                                ? () => router.visit(editHref)
                                : undefined
                        }
                        showEdit={canUpdate && Boolean(editHref)}
                        showDelete={canVoid && Boolean(onDelete)}
                        onDelete={onDelete}
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}
