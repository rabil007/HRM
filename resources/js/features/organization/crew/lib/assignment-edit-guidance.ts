import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentPagePermissions,
} from '../types';

export type EditPlanningChange = {
    field: 'vessel' | 'rank' | 'expected_join';
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

function resolveRankName(
    assignment: CrewAssignmentDetail,
    rankId: number | null,
    ranks: Array<{ id: number; name: string }>,
): string {
    if (rankId === (assignment.rank?.id ?? null)) {
        return assignment.rank?.name ?? '—';
    }

    return ranks.find((rank) => rank.id === rankId)?.name ?? '—';
}

function isPreP4Phase(assignment: CrewAssignmentDetail): boolean {
    const code = assignment.current_phase?.code?.toLowerCase() ?? '';

    return ['p0', 'p1', 'p2a', 'p2b', 'p3'].includes(code);
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
                    'Rank · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
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
        case 'p2a':
            return {
                explanation: 'Waiting to join the vessel.',
                safeToUpdate:
                    'Rank · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                destinationNote:
                    'Changing the vessel here updates the current mobilisation destination. This is not a Vessel Transfer because the employee has not boarded yet.',
                movementActionsNote:
                    'Actual joining still uses Join Vessel in Movement Actions.',
            };
        case 'p2b':
            return {
                explanation: 'Training is part of this same mobilisation.',
                safeToUpdate:
                    'Rank · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                destinationNote:
                    'Saving updates the destination of this mobilisation. Training history remains attached to the same assignment.',
                movementActionsNote:
                    'Do not create another assignment for the same mobilisation.',
            };
        case 'p3':
            return {
                explanation:
                    'Ready to board. Update destination/planning details only if the mobilisation plan changed before boarding.',
                safeToUpdate:
                    'Rank · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
                destinationNote:
                    'Because the employee has not boarded P4 yet, vessel changes here are not a Vessel Transfer.',
                movementActionsNote:
                    'Actual joining still uses Join Vessel in Movement Actions.',
            };
        default:
            return {
                explanation: `Editing ${assignment.assignment_no}.`,
                safeToUpdate:
                    'Rank · Arrival Date · Client · Vessel · Expected Vessel Join · Remarks',
            };
    }
}

function buildPlanningChanges(
    assignment: CrewAssignmentDetail,
    formData: CrewAssignmentFormData,
    vessels: Array<{ id: number; name: string }>,
    ranks: Array<{ id: number; name: string }>,
): EditPlanningChange[] {
    const changes: EditPlanningChange[] = [];

    const originalVesselId = assignment.vessel?.id ?? null;
    const originalRankId = assignment.rank?.id ?? null;
    const originalJoin = assignment.planned_join_at ?? '';

    if (formData.vessel_id !== originalVesselId) {
        changes.push({
            field: 'vessel',
            label: 'Vessel',
            from: resolveVesselName(assignment, originalVesselId, vessels),
            to: resolveVesselName(assignment, formData.vessel_id, vessels),
        });
    }

    if (formData.rank_id !== originalRankId) {
        changes.push({
            field: 'rank',
            label: 'Rank',
            from: resolveRankName(assignment, originalRankId, ranks),
            to: resolveRankName(assignment, formData.rank_id, ranks),
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
    ranks,
    permissions,
}: {
    assignment: CrewAssignmentDetail;
    formData: CrewAssignmentFormData;
    vessels: Array<{ id: number; name: string }>;
    ranks: Array<{ id: number; name: string }>;
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
        ranks,
    );
    const dateAdvisories = buildDateAdvisories(formData, assignment);

    const vesselChanged = planningChanges.some(
        (change) => change.field === 'vessel',
    );
    let destinationNote = phaseCopy.destinationNote;

    if (vesselChanged && isPreP4Phase(assignment)) {
        const vesselChange = planningChanges.find(
            (change) => change.field === 'vessel',
        );

        if (vesselChange) {
            destinationNote = `${vesselChange.from} → ${vesselChange.to}. This updates the current mobilisation destination. Because the employee has not boarded P4 yet, this is not a Vessel Transfer.`;
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
                ? 'If an eligible linked Planning record exists, these changes will synchronize when saved.'
                : undefined,
        dateAdvisories,
    };
}
