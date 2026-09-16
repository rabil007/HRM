import type {
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentWarning,
    CrewMovementAction,
} from '../types.ts';

export type QuickDetailPermissions = Pick<
    CrewAssignmentPagePermissions,
    'perform_movement' | 'cancel' | 'view_documents' | 'view_planning'
>;

export type QuickDetailIssue = Pick<
    CrewAssignmentWarning,
    'label' | 'message' | 'severity'
> & {
    key: string;
    source: 'Movement' | 'Documents';
};

const NEXT_MOVEMENT: Record<string, CrewMovementAction> = {
    p1: 'record_arrival',
    p2b: 'complete_training',
    p3: 'join_vessel',
    p4: 'plan_signoff',
    p5: 'travel_home',
};

function nextMovementForPhase(
    phase: string | undefined,
    availableActions: CrewMovementAction[],
): CrewMovementAction | undefined {
    if (!phase) {
        return undefined;
    }

    if (phase === 'p0') {
        if (availableActions.includes('record_arrival')) {
            return 'record_arrival';
        }

        if (availableActions.includes('approve_mobilisation')) {
            return 'approve_mobilisation';
        }

        return undefined;
    }

    if (phase === 'p2a') {
        if (availableActions.includes('join_vessel')) {
            return 'join_vessel';
        }

        if (availableActions.includes('send_to_training')) {
            return 'send_to_training';
        }

        return undefined;
    }

    return NEXT_MOVEMENT[phase];
}

const MOVEMENT_GUIDANCE: Record<string, string> = {
    p0: 'Pre-mobilisation is in progress. Record arrival when the crew member reaches the join location.',
    p1: 'Legacy Travel In assignment. Record arrival to move the crew member into Join Standby.',
    p2a: 'Crew member is on join standby. Send to training if required, or record Join Vessel when they actually board.',
    p2b: 'Record completion once the course is finished.',
    p3: 'Legacy Ready to Join assignment. Record Join Vessel when the crew member actually boards.',
    p4: 'Review the sign-off plan and relief arrangements.',
    p5: 'Confirm travel home or use another movement to redeploy.',
    p6: 'Review the record before closing this cycle or redeploying.',
};

export function datetimeLocalInTimezone(now: Date, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(now);

    const value = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${value('year')}-${value('month')}-${value('day')}T${value('hour')}:${value('minute')}`;
}

export function companyToday(now: Date, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);

    return ['year', 'month', 'day']
        .map((type) => parts.find((part) => part.type === type)?.value)
        .join('-');
}

export function calendarDayDifference(
    date: string | null | undefined,
    from: string,
): number | null {
    const day = date?.slice(0, 10);

    if (!day || !/^\d{4}-\d{2}-\d{2}$/.test(day)) {
        return null;
    }

    const difference =
        (Date.parse(`${day}T00:00:00Z`) -
            Date.parse(`${from.slice(0, 10)}T00:00:00Z`)) /
        86_400_000;

    return Number.isFinite(difference) ? difference : null;
}

export function relativePlanDate(days: number | null): string {
    if (days === null) {
        return 'Not planned';
    }

    if (days < 0) {
        return `${Math.abs(days)}d overdue`;
    }

    if (days === 0) {
        return 'Today';
    }

    return days === 1 ? 'Tomorrow' : `In ${days} days`;
}

export function crewQuickDetailModel(
    assignment: CrewAssignmentListItem,
    can: QuickDetailPermissions,
    now = new Date(),
) {
    const phase = assignment.current_phase?.code;
    const finished = ['closed', 'cancelled', 'voided'].includes(
        assignment.status,
    );
    const timezone =
        assignment.company_timezone ||
        assignment.movement_context.company_timezone ||
        'UTC';
    const today = companyToday(now, timezone);
    const readiness = assignment.mobilisation_readiness?.applies
        ? assignment.mobilisation_readiness
        : null;
    const availableActions = (assignment.available_actions ?? []).filter(
        (action) =>
            action === 'cancel_assignment' ? can.cancel : can.perform_movement,
    );
    const issues: QuickDetailIssue[] = (assignment.warnings ?? []).map(
        (warning, index) => ({
            ...warning,
            key: `movement-${warning.code}-${index}`,
            source: 'Movement',
        }),
    );

    for (const [index, problem] of (readiness?.problems ?? []).entries()) {
        issues.push({
            key: `document-${problem.code}-${problem.document_type_id}-${index}`,
            source: 'Documents',
            label: problem.label,
            message: problem.message,
            severity: problem.severity === 'critical' ? 'critical' : 'warning',
        });
    }

    const severityOrder = { critical: 0, warning: 1, info: 2 };
    issues.sort(
        (first, second) =>
            severityOrder[first.severity] - severityOrder[second.severity],
    );

    let milestone: { label: string; date: string | null } | null = null;

    if (!finished) {
        if (phase === 'p4') {
            milestone = {
                label: 'Planned sign-off',
                date: assignment.planned_signoff_at,
            };
        } else if (phase === 'p2b') {
            milestone = {
                label: 'Training completion',
                date: assignment.movement_context
                    .training_expected_completion_at,
            };
        } else if (phase === 'p0' && assignment.planned_arrival_at) {
            milestone = {
                label: 'Arrival Date',
                date: assignment.planned_arrival_at,
            };
        } else if (phase && ['p0', 'p1', 'p2a', 'p3'].includes(phase)) {
            milestone = {
                label: 'Expected Join',
                date: assignment.planned_join_at,
            };
        }
    }

    const daysUntilMilestone = milestone
        ? calendarDayDifference(milestone.date, today)
        : null;
    const movementCandidate = nextMovementForPhase(
        phase,
        availableActions as CrewMovementAction[],
    );
    const movement =
        !finished &&
        movementCandidate &&
        availableActions.includes(movementCandidate)
            ? movementCandidate
            : null;
    const needsDocumentReview =
        readiness !== null &&
        readiness.checks_total > 0 &&
        readiness.status !== 'ready';
    const needsRelief =
        !finished &&
        phase === 'p4' &&
        (assignment.relief_status === 'no_relief' ||
            assignment.relief_risk === 'critical' ||
            assignment.relief_risk === 'warning');
    const focus = finished
        ? 'This assignment is complete. Open the full record to review its history.'
        : needsDocumentReview
          ? 'Review outstanding document checks. These are advisory; permitted movements remain available.'
          : needsRelief
            ? 'Review relief coverage before confirming the planned sign-off.'
            : (MOVEMENT_GUIDANCE[phase ?? ''] ??
              'Open the full assignment to review the current movement.');

    return {
        availableActions,
        daysUntilMilestone,
        finished,
        focus,
        issues,
        milestone,
        movement,
        needsDocumentReview,
        needsRelief,
        readiness,
        timezone,
        reliefGapDays:
            phase === 'p4' &&
            assignment.relief_planned_join_date &&
            assignment.planned_signoff_at
                ? calendarDayDifference(
                      assignment.relief_planned_join_date,
                      assignment.planned_signoff_at,
                  )
                : null,
    };
}
