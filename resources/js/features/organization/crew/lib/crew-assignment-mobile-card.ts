import type { CrewAssignmentListItem } from '../types';

export type CrewAssignmentMobileCardModel = {
    title: string;
    subtitle: string;
    vesselName: string;
    phaseCode: string | null;
    phaseLabel: string | null;
    plannedSignoffAt: string | null;
    actualDisembarkationAt: string | null;
    attention: string | null;
    showEdit: boolean;
    showMovement: boolean;
    isOnVessel: boolean;
};

function mobileAttentionSummary(
    assignment: CrewAssignmentListItem,
): string | null {
    const firstWarning = assignment.warnings[0]?.label?.trim() ?? null;

    if (firstWarning) {
        return firstWarning;
    }

    const readiness = assignment.mobilisation_readiness;

    if (!readiness) {
        return null;
    }

    const label =
        readiness.checks_total === 0
            ? 'No Checks Configured'
            : readiness.status_label;

    return `Readiness: ${label}`;
}

export function crewAssignmentMobileCardModel(
    assignment: CrewAssignmentListItem,
    can: { update: boolean; performMovement: boolean; cancel: boolean },
): CrewAssignmentMobileCardModel {
    const isOnVessel = assignment.current_phase?.code === 'p4';

    return {
        title: assignment.employee?.name ?? 'Unassigned',
        subtitle: assignment.assignment_no,
        vesselName: assignment.vessel?.name?.trim() || '—',
        phaseCode: assignment.current_phase?.code ?? null,
        phaseLabel: assignment.current_phase?.label ?? null,
        plannedSignoffAt: assignment.planned_signoff_at,
        actualDisembarkationAt: null,
        attention: mobileAttentionSummary(assignment),
        showEdit: can.update && assignment.is_editable,
        showMovement:
            (can.performMovement || can.cancel) &&
            assignment.available_actions.length > 0,
        isOnVessel,
    };
}
