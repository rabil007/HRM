import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { crewAssignmentMobileCardModel } from '@/features/organization/crew/lib/crew-assignment-mobile-card';
import { reliefActionHref } from '@/features/organization/crew/lib/relief-action';
import type {
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
} from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';

export function CrewAssignmentMobileCard({
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

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.subtitle}
            meta={[
                model.vesselName,
                model.plannedSignoffAt
                    ? `Sign-off: ${formatDisplayDate(model.plannedSignoffAt)}`
                    : null,
            ]}
            status={
                model.phaseCode && model.phaseLabel ? (
                    <CrewPhaseBadge
                        code={model.phaseCode}
                        label={model.phaseLabel}
                        status={assignment.current_phase?.status}
                    />
                ) : undefined
            }
            attention={model.attention}
            href={viewHref}
            extraActions={
                model.showMovement ? (
                    <MovementActionMenu
                        assignmentId={assignment.id}
                        availableActions={assignment.available_actions}
                        movementContext={assignment.movement_context}
                        formOptions={formOptions}
                        size="sm"
                    />
                ) : undefined
            }
            overflowActions={overflowActions}
        />
    );
}
