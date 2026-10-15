import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentPagePermissions,
} from '../types';

export type EditPlanningChange = {
    field: 'vessel' | 'position' | 'expected_join';
    label: string;
    from: string;
    to: string;
};

export type EditDateAdvisory = {
    field: 'planned_arrival_at' | 'planned_join_at';
    message: string;
    severity: 'warning' | 'error';
};

export type AssignmentEditGuidance = {
    phaseLabel: string;
    summaryLine?: string;
    explanation: string;
    safeToUpdate?: string;
    destinationNote?: string;
    movementActionsNote?: string;
    planningChanges: EditPlanningChange[];
    planningSyncNote?: string;
    dateAdvisories: EditDateAdvisory[];
};

function phaseLabelFromAssignment(assignment: CrewAssignmentDetail): string {
    const code = assignment.current_phase?.code?.toUpperCase() ?? '';
    const label =
        assignment.current_phase?.label ?? assignment.status_label ?? '';

    return code ? `${code} · ${label}` : label;
}

function resolveVesselName(
    assignment: CrewAssignmentDetail,
    vesselId: number | null,
    vessels: Array<{ id: number; name: string }>,
): string {
    if (vesselId === (assignment.vessel?.id ?? null)) {
        return assignment.vessel?.name ?? '—';
    }

    return vessels.find((vessel) => vessel.id === vesselId)?.name ?? '—';
}

function resolvePositionName(
    assignment: CrewAssignmentDetail,
    positionId: number | null,
    positions: Array<{ id: number; name: string }>,
): string {
    if (positionId === (assignment.position?.id ?? null)) {
        return assignment.position?.name ?? '—';
    }

    return (
        positions.find((position) => position.id === positionId)?.name ?? '—'
    );
}

function isPreOnboardPhase(assignment: CrewAssignmentDetail): boolean {
    const code = assignment.current_phase?.code?.toLowerCase() ?? '';

    return ['p0', 'p1', 'p1a', 'p1b', 'p2a', 'p2b'].includes(code);
}

function buildPhaseExplanation(
    assignment: CrewAssignmentDetail,
): Pick<
    AssignmentEditGuidance,
    'explanation' | 'safeToUpdate' | 'destinationNote' | 'movementActionsNote'
> {
    const code = assignment.current_phase?.code?.toLowerCase() ?? '';

    switch (code) {
        case 'p0':
            return {
                explanation: 'This mobilisation is still being prepared.',
                safeToUpdate:
                    'Position · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                movementActionsNote:
                    'Actual operational events belong in Movement Actions.',
            };
        case 'p1':
            return {
                explanation:
                    'Travel has already started. Edit only the remaining planning details allowed by this form.',
                movementActionsNote:
                    'Actual arrival must remain a Movement Action.',
            };
        case 'p1a':
        case 'p2a':
            return {
                explanation: 'Waiting to join the vessel.',
                safeToUpdate:
                    'Position · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                destinationNote:
                    'Changing the vessel here updates the current mobilisation destination. This is not a Vessel Transfer because the employee has not boarded yet.',
                movementActionsNote:
                    'Actual joining still uses Join Vessel in Movement Actions.',
            };
        case 'p1b':
        case 'p2b':
            return {
                explanation: 'Training is part of this same mobilisation.',
                safeToUpdate:
                    'Position · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                destinationNote:
                    'Saving updates the destination of this mobilisation. Training history remains attached to the same assignment.',
                movementActionsNote:
                    'Do not create another assignment for the same mobilisation.',
            };
        case 'p2':
            return {
                explanation:
                    'On vessel. Update planning fields only when permitted for this phase.',
                movementActionsNote:
                    'Actual disembarkation and sign-off planning use Movement Actions.',
            };
        case 'p3':
            return {
                explanation:
                    'Demobilisation standby after disembarkation. Update planning details only when allowed.',
                movementActionsNote:
                    'Travel home and redeploy remain Movement Actions.',
            };
        case 'p4':
            return {
                explanation:
                    'Home or redeployment stage. Review before closing or redeploying.',
                movementActionsNote:
                    'Close Assignment and Redeploy use Movement Actions.',
            };
        default:
            return {
                explanation: `Editing ${assignment.assignment_no}.`,
                safeToUpdate:
                    'Position · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
            };
    }
}

function buildPlanningChanges(
    assignment: CrewAssignmentDetail,
    formData: CrewAssignmentFormData,
    vessels: Array<{ id: number; name: string }>,
    positions: Array<{ id: number; name: string }>,
): EditPlanningChange[] {
    const changes: EditPlanningChange[] = [];

    const originalVesselId = assignment.vessel?.id ?? null;
    const originalPositionId = assignment.position?.id ?? null;
    const originalJoin = assignment.planned_join_at ?? '';

    if (formData.vessel_id !== originalVesselId) {
        changes.push({
            field: 'vessel',
            label: 'Vessel',
            from: resolveVesselName(assignment, originalVesselId, vessels),
            to: resolveVesselName(assignment, formData.vessel_id, vessels),
        });
    }

    if (formData.position_id !== originalPositionId) {
        changes.push({
            field: 'position',
            label: 'Position',
            from: resolvePositionName(
                assignment,
                originalPositionId,
                positions,
            ),
            to: resolvePositionName(
                assignment,
                formData.position_id,
                positions,
            ),
        });
    }

    if ((formData.planned_join_at ?? '') !== originalJoin) {
        changes.push({
            field: 'expected_join',
            label: 'Expected Join',
            from: originalJoin || '—',
            to: formData.planned_join_at || '—',
        });
    }

    return changes;
}

function buildDateAdvisories(
    formData: CrewAssignmentFormData,
    assignment: CrewAssignmentDetail,
): EditDateAdvisory[] {
    const advisories: EditDateAdvisory[] = [];
    const arrival = formData.planned_arrival_at?.slice(0, 10) ?? '';
    const join = formData.planned_join_at?.slice(0, 10) ?? '';
    const signOff = assignment.planned_signoff_at?.slice(0, 10) ?? '';

    if (arrival && join && arrival > join) {
        advisories.push({
            field: 'planned_arrival_at',
            message: 'Arrival Date cannot be after Expected Vessel Join.',
            severity: 'error',
        });
    }

    if (join && signOff && join > signOff) {
        advisories.push({
            field: 'planned_join_at',
            message:
                'Expected Vessel Join is after the current Planned Sign-Off. Update the sign-off plan through the appropriate workflow first.',
            severity: 'warning',
        });
    }

    return advisories;
}

export function buildAssignmentEditGuidance({
    assignment,
    formData,
    vessels,
    positions,
    permissions,
}: {
    assignment: CrewAssignmentDetail;
    formData: CrewAssignmentFormData;
    vessels: Array<{ id: number; name: string }>;
    positions: Array<{ id: number; name: string }>;
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'perform_movement' | 'view_planning'
    >;
}): AssignmentEditGuidance {
    const phaseCopy = buildPhaseExplanation(assignment);
    const planningChanges = buildPlanningChanges(
        assignment,
        formData,
        vessels,
        positions,
    );
    const dateAdvisories = buildDateAdvisories(formData, assignment);

    const vesselChanged = planningChanges.some(
        (change) => change.field === 'vessel',
    );
    let destinationNote = phaseCopy.destinationNote;

    if (vesselChanged && isPreOnboardPhase(assignment)) {
        const vesselChange = planningChanges.find(
            (change) => change.field === 'vessel',
        );

        if (vesselChange) {
            destinationNote = `${vesselChange.from} → ${vesselChange.to}. This updates the current mobilisation destination. Because the employee has not boarded yet, this is not a Vessel Transfer.`;
        }
    }

    const summaryParts = [
        assignment.vessel?.name,
        assignment.assignment_no,
    ].filter(Boolean);

    return {
        phaseLabel: phaseLabelFromAssignment(assignment),
        summaryLine:
            summaryParts.length > 0 ? summaryParts.join(' · ') : undefined,
        explanation: phaseCopy.explanation,
        safeToUpdate: phaseCopy.safeToUpdate,
        destinationNote,
        movementActionsNote: permissions.perform_movement
            ? phaseCopy.movementActionsNote
            : undefined,
        planningChanges,
        planningSyncNote:
            planningChanges.length > 0
                ? 'Linked vacant Planning slots are not updated automatically. Edit the Planning slot separately if its vessel, position, or dates need to change.'
                : undefined,
        dateAdvisories,
    };
}
