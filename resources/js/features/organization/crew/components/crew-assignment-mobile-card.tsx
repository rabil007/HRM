import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { Checkbox } from '@/components/ui/checkbox';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import {
    CrewKeyOperationalDateFact,
    CrewOperatorAttentionFact,
    CrewOperatorStageFact,
} from '@/features/organization/crew/components/crew-operator-facts';
import { crewAssignmentMobileCardModel } from '@/features/organization/crew/lib/crew-assignment-mobile-card';
import { reliefActionHref } from '@/features/organization/crew/lib/relief-action';
import type {
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
} from '@/features/organization/crew/types';
import type { CrewMovementAction } from '@/features/organization/crew/types';

export function CrewAssignmentMobileCard({
    assignment,
    viewHref,
    editHref,
    canUpdate,
    canPerformMovement,
    canCancel,
    canVoid = false,
    formOptions,
    onView,
    selected = false,
    onToggleSelect,
    showSelect = false,
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
    const model = crewAssignmentMobileCardModel(assignment, {
        update: canUpdate,
        performMovement: canPerformMovement,
        cancel: canCancel,
    });
    const overflowActions: MobileRecordOverflowAction[] = [];
    const isOnVessel = model.isOnVessel;
    const reliefHref = isOnVessel ? reliefActionHref(assignment) : null;
    const reliefActionLabel =
        assignment.relief_action_label ??
        (assignment.relief_status === 'no_relief'
            ? 'Plan Relief'
            : assignment.relief_status === 'relief_planned'
              ? 'Open Relief Plan'
              : 'Open Relief Assignment');

    if (model.showEdit && editHref) {
        overflowActions.push({
            key: 'edit',
            label: 'Edit',
            href: editHref,
        });
    }

    if (reliefHref) {
        overflowActions.push({
            key: 'relief',
            label: reliefActionLabel,
            href: reliefHref,
        });
    }

    if (canVoid && onDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete Assignment',
            destructive: true,
            onSelect: onDelete,
        });
    }

    return (
        <MobileRecordCard
            leading={
                showSelect ? (
                    <Checkbox
                        checked={selected}
                        onCheckedChange={onToggleSelect}
                        aria-label={`Select ${model.title}`}
                    />
                ) : undefined
            }
            title={model.title}
            subtitle={model.subtitle}
            meta={[model.vesselName]}
            status={
                assignment.operator_stage ? (
                    <CrewOperatorStageFact stage={assignment.operator_stage} />
                ) : undefined
            }
            attention={
                assignment.operator_attention ? (
                    <CrewOperatorAttentionFact
                        attention={assignment.operator_attention}
                    />
                ) : (
                    model.attention
                )
            }
            href={onView ? undefined : viewHref}
            primaryAction={
                onView ? { label: 'Quick view', onClick: onView } : undefined
            }
            extraActions={
                <div className="flex flex-col items-end gap-2">
                    {assignment.key_operational_date ? (
                        <CrewKeyOperationalDateFact
                            value={assignment.key_operational_date}
                        />
                    ) : null}
                    {model.showMovement ? (
                        <MovementActionMenu
                            assignmentId={assignment.id}
                            availableActions={assignment.available_actions}
                            movementContext={assignment.movement_context}
                            formOptions={formOptions}
                            size="sm"
                            highlightedAction={
                                assignment.recommended_action?.type ===
                                    'movement' &&
                                assignment.recommended_action.action &&
                                (assignment.recommended_action.action ===
                                'cancel_assignment'
                                    ? canCancel
                                    : canPerformMovement)
                                    ? (assignment.recommended_action
                                          .action as CrewMovementAction)
                                    : null
                            }
                        />
                    ) : null}
                </div>
            }
            overflowActions={overflowActions}
        />
    );
}
