import type {
    ActiveOnVesselAssignment,
    CrewAssignmentPagePermissions,
    EmployeeOperationalStatus,
} from '../types';
import {
    canTransferFromP4,
    hasSelectedTransferDestination,
    isTransferWorkflowAppropriate,
} from './vessel-transfer-recommendation.ts';

export type ReadinessSeverity = 'info' | 'attention' | 'conflict' | 'blocked';

export type ReadinessActionKey =
    | 'continue_assignment'
    | 'open_assignment'
    | 'edit_mobilisation'
    | 'cancel_assignment'
    | 'transfer_vessel'
    | 'plan_future'
    | 'redeploy'
    | 'return_home'
    | 'close_assignment'
    | 'join_vessel'
    | 'record_arrival';

export type ReadinessAction = {
    key: ReadinessActionKey;
    label: string;
    description?: string;
    kind: 'link' | 'transfer';
    emphasis?: 'primary' | 'secondary';
};

export type ReadinessAdvisory = {
    title: string;
    message: string;
    severity: 'attention' | 'conflict';
};

export type AssignmentReadinessGuidance = {
    phaseLabel: string;
    summaryLine?: string;
    explanation: string;
    severity: ReadinessSeverity;
    actions: ReadinessAction[];
    warning?: string;
    showWhyBlocked?: boolean;
    transferPermissionNote?: string;
    destinationAdvisory?: ReadinessAdvisory;
    plannedDateAdvisory?: ReadinessAdvisory;
};

export type AssignmentReadinessGuidanceContext = {
    status: EmployeeOperationalStatus | null;
    activeOnVessel: ActiveOnVesselAssignment | null;
    destinationVesselId: number | null;
    destinationVesselName: string | null;
    plannedJoinAt: string | null;
    employeeName: string;
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'view' | 'update' | 'perform_movement' | 'cancel' | 'view_planning'
    >;
    vessels: Array<{ id: number; name: string }>;
    maxHomeDays?: number | null;
};

type VesselRef = { id: number; name: string };

const ACTIVE_ASSIGNMENT_WARNING =
    'Another active assignment is blocked to prevent overlapping movement/payroll records.';

function resolveCurrentVesselId(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
    vessels: VesselRef[],
): number | null {
    if (activeOnVessel?.vessel_id) {
        return activeOnVessel.vessel_id;
    }

    const vesselName = status?.vessel_name ?? status?.current_vessel;

    if (!vesselName) {
        return null;
    }

    return vessels.find((vessel) => vessel.name === vesselName)?.id ?? null;
}

function resolveCurrentVesselName(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
): string | null {
    return (
        status?.vessel_name ??
        status?.current_vessel ??
        activeOnVessel?.vessel_name ??
        null
    );
}

function resolveAssignmentId(
    status: EmployeeOperationalStatus | null,
    activeOnVessel: ActiveOnVesselAssignment | null,
): number | null {
    return status?.assignment_id ?? activeOnVessel?.assignment_id ?? null;
}

function phaseCodeLabel(status: EmployeeOperationalStatus): string {
    const code = status.current_phase?.toUpperCase() ?? '';
    const label = status.label ?? '';

    return code ? `${code} · ${label}` : label;
}

function buildAssignmentSummaryLine(
    status: EmployeeOperationalStatus,
    vesselName: string | null,
): string | undefined {
    const parts: string[] = [];

    if (vesselName) {
        parts.push(vesselName);
    }

    if (status.assignment_no) {
        parts.push(status.assignment_no);
    }

    if (status.days_in_phase != null) {
        const dayLabel = status.days_in_phase === 1 ? 'day' : 'days';
        const phaseCode = status.current_phase?.toLowerCase();

        if (phaseCode === 'p4') {
            parts.push(`${status.days_in_phase} ${dayLabel} onboard`);
        } else if (phaseCode) {
            parts.push(`${status.days_in_phase} ${dayLabel} in phase`);
        }
    }

    if (
        status.planned_next_date &&
        status.current_phase?.toLowerCase() === 'p4'
    ) {
        parts.push(`Sign-off: ${status.planned_next_date.slice(0, 10)}`);
    }

    return parts.length > 0 ? parts.join(' · ') : undefined;
}

function canViewAssignmentDetails(
    status: EmployeeOperationalStatus | null,
    permissions: AssignmentReadinessGuidanceContext['permissions'],
): boolean {
    return permissions.view && status?.assignment_no != null;
}

function addAction(
    actions: ReadinessAction[],
    action: ReadinessAction | null,
): void {
    if (action === null) {
        return;
    }

    if (actions.some((existing) => existing.key === action.key)) {
        return;
    }

    actions.push(action);
}

function openAssignmentAction(
    label = 'Open Assignment',
    description = 'Continue current mobilisation',
    emphasis: 'primary' | 'secondary' = 'primary',
): ReadinessAction {
    return {
        key: 'open_assignment',
        label,
        description,
        kind: 'link',
        emphasis,
    };
}

function continueAssignmentAction(): ReadinessAction {
    return {
        key: 'continue_assignment',
        label: 'Continue',
        description: 'Continue current mobilisation',
        kind: 'link',
        emphasis: 'primary',
    };
}

function editMobilisationAction(): ReadinessAction {
    return {
        key: 'edit_mobilisation',
        label: 'Edit Mobilisation',
        description: 'Update vessel, rank, or dates on this mobilisation',
        kind: 'link',
        emphasis: 'secondary',
    };
}

function planFutureAction(
    permissions: AssignmentReadinessGuidanceContext['permissions'],
): ReadinessAction | null {
    if (!permissions.view_planning) {
        return null;
    }

    return {
        key: 'plan_future',
        label: 'Plan Future',
        description: 'Schedule later work in Crew Planning',
        kind: 'link',
        emphasis: 'secondary',
    };
}

function transferAction(destinationVesselName: string | null): ReadinessAction {
    const label = destinationVesselName
        ? `Transfer to ${destinationVesselName}`
        : 'Transfer Vessel';

    return {
        key: 'transfer_vessel',
        label,
        description: 'Move directly to another vessel',
        kind: 'transfer',
        emphasis: 'primary',
    };
}

function buildDestinationAdvisory(
    context: AssignmentReadinessGuidanceContext,
): ReadinessAdvisory | undefined {
    const {
        status,
        activeOnVessel,
        destinationVesselId,
        destinationVesselName,
    } = context;

    if (
        !status?.has_active_assignment ||
        destinationVesselId === null ||
        destinationVesselId < 1
    ) {
        return undefined;
    }

    const currentVesselId = resolveCurrentVesselId(
        status,
        activeOnVessel,
        context.vessels,
    );
    const currentVesselName = resolveCurrentVesselName(status, activeOnVessel);

    if (
        currentVesselId === null ||
        currentVesselId === destinationVesselId ||
        !currentVesselName ||
        !destinationVesselName
    ) {
        return undefined;
    }

    if (status.status === 'on_vessel') {
        return {
            title: 'Direct vessel change detected',
            severity: 'conflict',
            message: `Current: ${currentVesselName}. Selected: ${destinationVesselName}. Use Transfer Vessel instead of starting another active assignment.`,
        };
    }

    if (
        [
            'pre_mobilisation',
            'travel_in',
            'join_standby',
            'training',
            'ready_to_join',
        ].includes(status.status)
    ) {
        return {
            title: 'Destination changed',
            severity: 'attention',
            message: `Update this mobilisation if ${destinationVesselName} replaces ${currentVesselName}.`,
        };
    }

    return undefined;
}

function buildPlannedDateAdvisory(
    context: AssignmentReadinessGuidanceContext,
): ReadinessAdvisory | undefined {
    const { status, plannedJoinAt } = context;

    // planned_next_date is phase-dependent (join / sign-off / travel) and must not
    // be treated as a universal planned sign-off field outside P4 On Vessel.
    if (
        status?.status !== 'on_vessel' ||
        !plannedJoinAt ||
        !status.planned_next_date
    ) {
        return undefined;
    }

    const plannedJoin = plannedJoinAt.slice(0, 10);
    const plannedSignOff = status.planned_next_date.slice(0, 10);

    if (plannedJoin >= plannedSignOff) {
        return undefined;
    }

    return {
        title: 'Planned date overlap',
        severity: 'conflict',
        message:
            'Expected Join is before the current Planned Sign-Off. Use Transfer Vessel for a direct vessel move, or update the current sign-off plan.',
    };
}

function buildAvailableGuidance(
    context: AssignmentReadinessGuidanceContext,
): AssignmentReadinessGuidance {
    const { status, maxHomeDays } = context;
    const daysAtHome = status?.days_at_home ?? status?.in_home_days ?? null;
    const overLimit = status?.availability_status === 'over_limit';

    if (overLimit && daysAtHome != null && maxHomeDays != null) {
        const daysOver = daysAtHome - maxHomeDays;

        return {
            phaseLabel: 'AVAILABLE',
            summaryLine: `Home: ${daysAtHome} days · Target: ${maxHomeDays}`,
            explanation: `${daysOver} days over target. Available for a new mobilisation.`,
            severity: 'attention',
            actions: [],
        };
    }

    const summaryLine =
        daysAtHome != null && maxHomeDays != null
            ? `Home: ${daysAtHome} days · Target: ${maxHomeDays}`
            : undefined;

    const explanation =
        daysAtHome != null && maxHomeDays != null
            ? `${Math.max(0, maxHomeDays - daysAtHome)} days remaining. Available for a new mobilisation.`
            : 'Available for a new mobilisation.';

    return {
        phaseLabel: 'AVAILABLE',
        summaryLine,
        explanation,
        severity: 'info',
        actions: [],
    };
}

function buildRestrictedActiveGuidance(): AssignmentReadinessGuidance {
    return {
        phaseLabel: 'ACTIVE ASSIGNMENT',
        explanation:
            'This employee already has an active assignment. Ask an authorized Operations user to review it.',
        severity: 'attention',
        actions: [],
        showWhyBlocked: true,
    };
}

function buildPhaseGuidance(
    context: AssignmentReadinessGuidanceContext,
): AssignmentReadinessGuidance {
    const { status, activeOnVessel, permissions } = context;

    if (!status) {
        return buildAvailableGuidance(context);
    }

    if (!status.has_active_assignment) {
        return buildAvailableGuidance(context);
    }

    const assignmentId = resolveAssignmentId(status, activeOnVessel);

    if (assignmentId === null) {
        return buildRestrictedActiveGuidance();
    }

    if (!canViewAssignmentDetails(status, permissions)) {
        return buildRestrictedActiveGuidance();
    }

    const vesselName = resolveCurrentVesselName(status, activeOnVessel);
    const phaseLabel = phaseCodeLabel(status);
    const summaryLine = buildAssignmentSummaryLine(status, vesselName);
    const destinationAdvisory = buildDestinationAdvisory(context);
    const plannedDateAdvisory = buildPlannedDateAdvisory(context);
    const actions: ReadinessAction[] = [];

    const baseActive = {
        phaseLabel,
        summaryLine,
        severity: (destinationAdvisory?.severity === 'conflict'
            ? 'conflict'
            : 'attention') as ReadinessSeverity,
        showWhyBlocked: true,
        warning: ACTIVE_ASSIGNMENT_WARNING,
        destinationAdvisory,
        plannedDateAdvisory,
    };

    switch (status.status) {
        case 'pre_mobilisation':
            addAction(actions, continueAssignmentAction());

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation: 'Mobilisation is already being prepared.',
                actions,
            };

        case 'travel_in':
            addAction(actions, continueAssignmentAction());
            addAction(actions, openAssignmentAction());
            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation:
                    'Already travelling under the current mobilisation.',
                actions,
            };

        case 'join_standby': {
            const joinSummary = vesselName
                ? `Preparing to join ${vesselName}`
                : undefined;

            addAction(actions, continueAssignmentAction());

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                summaryLine: joinSummary ?? summaryLine,
                explanation: 'Already in an active mobilisation.',
                actions,
            };
        }

        case 'training':
            addAction(actions, continueAssignmentAction());

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation: 'Training is part of the current mobilisation.',
                actions,
            };

        case 'ready_to_join':
            addAction(
                actions,
                openAssignmentAction(
                    'Join Vessel',
                    'Board the intended vessel from Movement Actions',
                ),
            );

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation: 'Ready to board the intended vessel.',
                actions,
            };

        case 'on_vessel': {
            const canExecuteTransfer = canTransferFromP4(
                activeOnVessel,
                permissions.perform_movement,
            );
            const hasDestination = hasSelectedTransferDestination(
                activeOnVessel,
                context.destinationVesselId,
            );
            const transferWorkflowAppropriate =
                isTransferWorkflowAppropriate(
                    status,
                    activeOnVessel,
                    context.destinationVesselId,
                ) || destinationAdvisory?.severity === 'conflict';
            let transferPermissionNote: string | undefined;

            addAction(actions, openAssignmentAction());

            if (canExecuteTransfer) {
                addAction(
                    actions,
                    transferAction(
                        hasDestination ? context.destinationVesselName : null,
                    ),
                );
            } else if (transferWorkflowAppropriate) {
                transferPermissionNote =
                    'A vessel transfer is the correct workflow for this change. You do not have permission to perform this movement. Ask an authorized Crewing user.';
            }

            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation:
                    'Currently onboard. Choose the correct next workflow.',
                actions,
                transferPermissionNote,
            };
        }

        case 'demob_standby':
            addAction(actions, {
                key: 'return_home',
                label: 'Return Home',
                description: 'Record return home from Movement Actions',
                kind: 'link',
                emphasis: 'primary',
            });
            addAction(actions, {
                key: 'redeploy',
                label: 'Redeploy',
                description: 'Start linked redeployment from Movement Actions',
                kind: 'link',
                emphasis: 'secondary',
            });
            addAction(actions, planFutureAction(permissions));
            addAction(
                actions,
                openAssignmentAction(
                    'Open Assignment',
                    'Review demobilisation details',
                    'secondary',
                ),
            );

            return {
                ...baseActive,
                explanation: 'Disembarked and awaiting next movement.',
                actions,
            };

        case 'home_redeploy':
            addAction(actions, {
                key: 'close_assignment',
                label: 'Close',
                description: 'Close the current cycle when complete',
                kind: 'link',
                emphasis: 'secondary',
            });
            addAction(actions, {
                key: 'redeploy',
                label: 'Redeploy',
                description: 'Start another mobilisation through Redeploy',
                kind: 'link',
                emphasis: 'primary',
            });
            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation: 'Current mobilisation is in its final stage.',
                actions,
            };

        default:
            addAction(actions, continueAssignmentAction());
            addAction(actions, planFutureAction(permissions));

            return {
                ...baseActive,
                explanation:
                    'Continue the existing mobilisation instead of starting another.',
                actions,
            };
    }
}

export function buildAssignmentReadinessGuidance(
    context: AssignmentReadinessGuidanceContext,
): AssignmentReadinessGuidance | null {
    if (!context.status) {
        return null;
    }

    return buildPhaseGuidance(context);
}

export function readinessSeverityClassName(
    severity: ReadinessSeverity,
): string {
    switch (severity) {
        case 'info':
            return 'border-border/60 bg-muted/15 text-foreground';
        case 'attention':
            return 'border-amber-500/35 bg-amber-500/10 text-amber-950 dark:text-amber-100';
        case 'conflict':
            return 'border-orange-500/40 bg-orange-500/10 text-orange-950 dark:text-orange-100';
        case 'blocked':
            return 'border-destructive/40 bg-destructive/10 text-destructive';
        default:
            return 'border-border/60 bg-muted/15 text-foreground';
    }
}

export function readinessAdvisoryClassName(
    severity: ReadinessAdvisory['severity'],
): string {
    return severity === 'conflict'
        ? readinessSeverityClassName('conflict')
        : readinessSeverityClassName('attention');
}
