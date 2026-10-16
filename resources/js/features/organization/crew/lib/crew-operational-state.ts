import type {
    CrewAssignmentListItem,
    CrewMovementAction,
    CrewRecommendedAction,
} from '../types.ts';
import { CREW_MOVEMENT_ACTION_LABELS } from '../types.ts';
import { isOnVesselPhaseCode } from './crew-phase-visibility.ts';

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

const ACTIVE_STAGE_LABELS: Record<string, string> = {
    p0: 'Awaiting Arrival',
    p1a: 'Awaiting Vessel Joining',
    p1b: 'In Training',
    p2: 'On Vessel',
    p3: 'Awaiting Home / Redeployment',
    p4: 'Home / Awaiting Closure',
};

export function operatorStageLabel(
    assignment: Pick<
        CrewAssignmentListItem,
        'current_phase' | 'status' | 'status_label' | 'operator_stage'
    >,
): string {
    if (assignment.operator_stage?.label) {
        return assignment.operator_stage.label;
    }

    if (assignment.status === 'draft') {
        return 'Not Started';
    }

    if (assignment.status === 'completed') {
        return 'Completed';
    }

    if (assignment.status === 'cancelled') {
        return 'Cancelled';
    }

    const code = assignment.current_phase?.code?.toLowerCase();

    if (code && ACTIVE_STAGE_LABELS[code]) {
        return ACTIVE_STAGE_LABELS[code];
    }

    return (
        assignment.current_phase?.label ??
        assignment.status_label ??
        assignment.status
    );
}

export function operatorStageSecondary(
    assignment: Pick<
        CrewAssignmentListItem,
        'current_phase' | 'status' | 'operator_stage'
    >,
): string | null {
    if (assignment.operator_stage) {
        return assignment.operator_stage.secondary;
    }

    if (assignment.status !== 'active' || !assignment.current_phase) {
        return null;
    }

    return `${assignment.current_phase.code.toUpperCase()} · ${assignment.current_phase.label}`;
}

export function currentStateLabel(
    assignment: Pick<
        CrewAssignmentListItem,
        'current_phase' | 'status' | 'status_label' | 'operator_stage'
    >,
): string {
    return operatorStageLabel(assignment);
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

/**
 * Canonical Operator next-step button set for the assignment detail panel.
 * Deduplicates recommended movement / anyway / available_actions so Draft→Active
 * (and similar) never render twice under different labels.
 */
export type OperatorNextStepButton = {
    kind: 'href' | 'movement' | 'anyway' | 'cancel';
    label: string;
    action?: CrewMovementAction;
    href?: string;
    emphasis?: 'primary' | 'secondary';
};

export type OperatorReliefAction = {
    href: string;
    label: string;
};

function reliefNeedsPlanningPermission(label: string): boolean {
    return label === 'Plan Relief' || label === 'Open Relief Plan';
}

function insertReliefAction(
    buttons: OperatorNextStepButton[],
    relief: OperatorReliefAction | null | undefined,
    canViewPlanning: boolean,
): void {
    if (!relief?.href) {
        return;
    }

    if (reliefNeedsPlanningPermission(relief.label) && !canViewPlanning) {
        return;
    }

    if (
        buttons.some(
            (button) =>
                button.href === relief.href || button.label === relief.label,
        )
    ) {
        return;
    }

    const reliefButton: OperatorNextStepButton = {
        kind: 'href',
        href: relief.href,
        label: relief.label,
        emphasis: 'secondary',
    };
    const planSignoffIndex = buttons.findIndex(
        (button) => button.action === 'plan_signoff',
    );

    if (planSignoffIndex >= 0) {
        buttons.splice(planSignoffIndex + 1, 0, reliefButton);

        return;
    }

    const transferIndex = buttons.findIndex(
        (button) => button.action === 'transfer_vessel',
    );

    if (transferIndex >= 0) {
        buttons.splice(transferIndex, 0, reliefButton);

        return;
    }

    buttons.push(reliefButton);
}

export function resolveOperatorNextStepButtons(options: {
    recommended: CrewRecommendedAction | null | undefined;
    availableActions: string[];
    permissions: OperationalStatePermissions;
    canViewDocuments: boolean;
    canViewPlanning: boolean;
    reliefAction?: OperatorReliefAction | null;
}): OperatorNextStepButton[] {
    const { recommended, availableActions, permissions } = options;
    const buttons: OperatorNextStepButton[] = [];

    const recommendedMovement =
        recommended?.type === 'movement' && recommended.action
            ? (recommended.action as CrewMovementAction)
            : null;

    if (recommendedMovement) {
        buttons.push({
            kind: 'movement',
            action: recommendedMovement,
            label:
                CREW_MOVEMENT_ACTION_LABELS[recommendedMovement] ??
                recommended?.label ??
                recommendedMovement,
        });
    }

    const recommendedHref =
        recommended?.href &&
        ((recommended.type === 'readiness' && options.canViewDocuments) ||
            (recommended.type === 'relief' && options.canViewPlanning) ||
            recommended.type === 'movement')
            ? recommended.href
            : null;

    if (recommendedHref && !recommendedMovement) {
        buttons.push({
            kind: 'href',
            href: recommendedHref,
            label:
                recommended?.type === 'readiness'
                    ? 'Open Documents'
                    : (recommended?.label ?? 'Open'),
        });
    }

    if (
        recommended?.anyway_action &&
        permissions.perform_movement &&
        availableActions.includes(recommended.anyway_action)
    ) {
        buttons.push({
            kind: 'anyway',
            action: recommended.anyway_action as CrewMovementAction,
            label: recommended.anyway_label ?? 'Continue Anyway',
        });
    }

    for (const action of otherValidMovementActions(
        availableActions,
        recommended,
        permissions,
    )) {
        buttons.push({
            kind: 'movement',
            action,
            label: formatOtherValidActionLabel(action),
        });
    }

    insertReliefAction(buttons, options.reliefAction, options.canViewPlanning);

    if (permissions.cancel && availableActions.includes('cancel_assignment')) {
        buttons.push({
            kind: 'cancel',
            action: 'cancel_assignment',
            label: CREW_MOVEMENT_ACTION_LABELS.cancel_assignment,
        });
    }

    return buttons;
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
        isOnVesselPhaseCode(assignment.current_phase?.code) &&
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
