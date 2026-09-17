import type {
    CrewAssignmentListItem,
    CrewMovementAction,
    CrewRecommendedAction,
} from '../types.ts';
import { CREW_MOVEMENT_ACTION_LABELS } from '../types.ts';

export type OperationalStatePermissions = {
    perform_movement: boolean;
    cancel: boolean;
};

export function permittedMovementActions(
    availableActions: string[] | undefined,
    permissions: OperationalStatePermissions,
): CrewMovementAction[] {
    return (availableActions ?? []).filter(
        (action): action is CrewMovementAction => {
            if (action === 'cancel_assignment') {
                return permissions.cancel;
            }

            return permissions.perform_movement;
        },
    );
}

export function otherValidMovementActions(
    availableActions: string[] | undefined,
    recommended: CrewRecommendedAction | null | undefined,
    permissions: OperationalStatePermissions,
): CrewMovementAction[] {
    const permitted = permittedMovementActions(availableActions, permissions);
    const recommendedAction =
        recommended?.type === 'movement' ? recommended.action : null;
    const excluded = new Set(
        [
            recommendedAction,
            recommended?.anyway_action,
            'cancel_assignment',
        ].filter(Boolean),
    );

    return permitted.filter((action) => !excluded.has(action));
}

export function currentStateLabel(
    assignment: Pick<
        CrewAssignmentListItem,
        'current_phase' | 'status' | 'status_label'
    >,
): string {
    if (assignment.current_phase) {
        const code = assignment.current_phase.code.toUpperCase();

        return `${code} · ${assignment.current_phase.label}`;
    }

    return assignment.status_label ?? assignment.status;
}

export function nextNormalActionLabel(
    recommended: CrewRecommendedAction | null | undefined,
): string | null {
    if (!recommended) {
        return null;
    }

    if (recommended.type === 'movement') {
        return recommended.label;
    }

    if (recommended.type === 'readiness') {
        return recommended.label;
    }

    if (recommended.type === 'relief') {
        return recommended.label;
    }

    return recommended.label;
}

export function formatOtherValidActionLabel(
    action: CrewMovementAction,
): string {
    return CREW_MOVEMENT_ACTION_LABELS[action] ?? action;
}

export type LastOperationalChangeInput = Pick<
    CrewAssignmentListItem,
    'actual_join_at' | 'actual_disembarkation_at' | 'vessel' | 'current_phase'
>;

export function lastOperationalChangeSummary(
    assignment: LastOperationalChangeInput,
): { label: string; occurredAt: string } | null {
    if (assignment.actual_disembarkation_at) {
        return {
            label: 'Disembarked',
            occurredAt: assignment.actual_disembarkation_at,
        };
    }

    if (
        assignment.actual_join_at &&
        assignment.current_phase?.code === 'p4' &&
        assignment.vessel?.name
    ) {
        return {
            label: `Joined ${assignment.vessel.name}`,
            occurredAt: assignment.actual_join_at,
        };
    }

    if (assignment.current_phase?.started_at) {
        return {
            label: `${assignment.current_phase.label} started`,
            occurredAt: assignment.current_phase.started_at,
        };
    }

    return null;
}
