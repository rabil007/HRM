import type {
    ActiveOnVesselAssignment,
    CrewAssignmentPagePermissions,
    EmployeeOperationalStatus,
} from '../types';
import { recommendsVesselTransfer } from './vessel-transfer-recommendation.ts';

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

export type ReadinessIntentOption = {
    question: string;
    hint: string;
    actionKey: ReadinessActionKey;
};

export type ReadinessAlert = {
    title: string;
    message: string;
    severity: 'attention' | 'conflict';
};

export type AssignmentReadinessGuidance = {
    title: string;
    description: string;
    guidance?: string;
    secondaryGuidance?: string;
    severity: ReadinessSeverity;
    actions: ReadinessAction[];
    intentOptions?: ReadinessIntentOption[];
    attention?: {
        title: string;
        message: string;
    };
    showWhyBlocked?: boolean;
    destinationAlert?: ReadinessAlert;
    plannedDateAlert?: ReadinessAlert;
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
    assignmentId: number,
    label: string,
    description?: string,
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

function continueAssignmentAction(
    assignmentId: number,
    assignmentNo: string | null,
): ReadinessAction {
    return {
        key: 'continue_assignment',
        label: assignmentNo
            ? `Continue ${assignmentNo}`
            : 'Continue Assignment',
        description: 'Open the current mobilisation cycle.',
        kind: 'link',
        emphasis: 'primary',
    };
}

function editMobilisationAction(): ReadinessAction {
    return {
        key: 'edit_mobilisation',
        label: 'Edit Current Mobilisation',
        description:
            'Update vessel, rank, expected join, or arrival details on the existing assignment.',
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
        label: 'Plan Future Assignment',
        description:
            'Record future crew work in Crew Planning without starting another active mobilisation.',
        kind: 'link',
        emphasis: 'secondary',
    };
}

function transferAction(
    activeOnVessel: ActiveOnVesselAssignment,
    destinationVesselName: string | null,
    canTransfer: boolean,
): ReadinessAction | null {
    if (!canTransfer) {
        return null;
    }

    const label = destinationVesselName
        ? `Transfer to ${destinationVesselName}`
        : 'Transfer Vessel';

    return {
        key: 'transfer_vessel',
        label,
        description:
            'Use the existing Transfer Vessel workflow on the current assignment.',
        kind: 'transfer',
        emphasis: 'primary',
    };
}

function buildDestinationAlert(
    context: AssignmentReadinessGuidanceContext,
): ReadinessAlert | undefined {
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

    const employee = context.employeeName;

    if (status.status === 'on_vessel') {
        return {
            title: 'Different vessel selected',
            severity: 'conflict',
            message: `${employee} is currently onboard ${currentVesselName}, while this form is targeting ${destinationVesselName}. If this represents a direct vessel change, use Transfer Vessel instead of Start Assignment.`,
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
            message: `The active mobilisation currently targets ${currentVesselName}, while this form targets ${destinationVesselName}. If ${destinationVesselName} replaces ${currentVesselName} for the current mobilisation, update the existing assignment instead of creating a second active assignment.`,
        };
    }

    return undefined;
}

function buildPlannedDateAlert(
    context: AssignmentReadinessGuidanceContext,
): ReadinessAlert | undefined {
    const { status, plannedJoinAt } = context;

    if (
        !status?.has_active_assignment ||
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
        severity: status.status === 'on_vessel' ? 'conflict' : 'attention',
        message:
            "The new Expected Vessel Join is before the current assignment's Planned Sign-Off. If this represents a direct vessel change, use Transfer Vessel. If the existing Planned Sign-Off changed, update the current assignment.",
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
            title: 'Available — Over Home Target',
            description: `Home for ${daysAtHome} days. Availability rule: ${maxHomeDays} days. ${daysOver} days over target.`,
            guidance:
                'This employee has exceeded the configured home availability target and may require operational attention.',
            severity: 'attention',
            actions: [],
        };
    }

    const withinDescription =
        daysAtHome != null && maxHomeDays != null
            ? `Home for ${daysAtHome} days. Availability rule: ${maxHomeDays} days. ${Math.max(0, maxHomeDays - daysAtHome)} days remaining.`
            : 'No active Crew Assignment exists. This employee can start a new mobilisation.';

    return {
        title: 'Available for Assignment',
        description: withinDescription,
        guidance:
            'No active Crew Assignment exists. This employee can start a new mobilisation.',
        severity: 'info',
        actions: [],
    };
}

function buildRestrictedActiveGuidance(): AssignmentReadinessGuidance {
    return {
        title: 'Active Crew Assignment',
        description:
            'This employee already has an active Crew Assignment. Another operational mobilisation cannot be started from here.',
        guidance:
            'Ask an authorized Operations user to review the current assignment and choose the correct workflow.',
        severity: 'attention',
        actions: [],
        showWhyBlocked: true,
    };
}

function buildPhaseGuidance(
    context: AssignmentReadinessGuidanceContext,
): AssignmentReadinessGuidance {
    const { status, activeOnVessel, permissions, employeeName } = context;

    if (!status) {
        return buildAvailableGuidance(context);
    }

    if (!status.has_active_assignment) {
        return buildAvailableGuidance(context);
    }

    const assignmentId = resolveAssignmentId(status, activeOnVessel);
    const assignmentNo =
        status.assignment_no ?? activeOnVessel?.assignment_no ?? null;
    const vesselName = resolveCurrentVesselName(status, activeOnVessel);
    const canView = canViewAssignmentDetails(status, permissions);
    const actions: ReadinessAction[] = [];

    if (!canView || assignmentId === null) {
        return buildRestrictedActiveGuidance();
    }

    const destinationAlert = buildDestinationAlert(context);
    const plannedDateAlert = buildPlannedDateAlert(context);

    switch (status.status) {
        case 'pre_mobilisation':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            if (permissions.cancel) {
                addAction(
                    actions,
                    openAssignmentAction(
                        assignmentId,
                        'Cancel Assignment',
                        'Cancel the current mobilisation from the assignment when permitted.',
                        'secondary',
                    ),
                );
            }

            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Pre-Mobilisation',
                description:
                    'This employee already has a mobilisation being prepared.',
                guidance:
                    'If the vessel, rank, expected arrival, expected join date or other mobilisation details have changed, update the existing assignment instead of starting another mobilisation cycle.',
                severity: 'attention',
                actions,
                attention: {
                    title: 'Duplicate mobilisation risk',
                    message:
                        'Creating another operational assignment for the same mobilisation could create duplicate records.',
                },
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        case 'travel_in':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );
            addAction(
                actions,
                openAssignmentAction(
                    assignmentId,
                    'Open Current Assignment',
                    'Review travel and mobilisation details.',
                ),
            );
            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Travel In',
                description:
                    'This employee is already travelling as part of the current mobilisation.',
                guidance:
                    'Continue the existing movement. If the destination has changed, review the current assignment rather than starting another active mobilisation.',
                severity: 'attention',
                actions,
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        case 'join_standby': {
            const description = vesselName
                ? `This employee has already mobilised and is waiting before vessel joining. Currently preparing to join ${vesselName}.`
                : 'This employee has already mobilised and is waiting before vessel joining.';

            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Join Standby',
                description,
                guidance:
                    'If the intended vessel changed before boarding, review or change the current mobilisation rather than creating another active assignment.',
                secondaryGuidance:
                    'If this is work that should happen after the current mobilisation, use Crew Planning.',
                severity: 'attention',
                actions,
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };
        }

        case 'training':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Training in Progress',
                description:
                    'This employee is currently completing training as part of the active mobilisation.',
                guidance:
                    'Complete or continue this mobilisation before starting another operational cycle.',
                severity: 'attention',
                actions,
                attention: {
                    title: 'Overlapping mobilisation risk',
                    message:
                        'Starting another active assignment before this mobilisation is completed or cancelled could create overlapping operational states.',
                },
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        case 'ready_to_join':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );
            addAction(
                actions,
                openAssignmentAction(
                    assignmentId,
                    'Join Vessel',
                    'Board the intended vessel from the current assignment when movement is permitted.',
                ),
            );

            if (permissions.update) {
                addAction(actions, editMobilisationAction());
            }

            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Ready to Join',
                description:
                    'This employee is ready to board the intended vessel.',
                guidance:
                    'If the vessel changed before boarding, update the existing mobilisation. Do not start a second active assignment.',
                severity: 'attention',
                actions,
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        case 'on_vessel': {
            const onVesselDescription = `${employeeName} is currently onboard ${vesselName ?? 'the assigned vessel'}${assignmentNo ? ` under ${assignmentNo}` : ''}.`;
            const transferRecommended = recommendsVesselTransfer(
                activeOnVessel,
                context.destinationVesselId,
            );
            const canTransfer =
                permissions.perform_movement &&
                activeOnVessel?.can_transfer === true &&
                transferRecommended;

            if (canTransfer && activeOnVessel) {
                addAction(
                    actions,
                    transferAction(
                        activeOnVessel,
                        context.destinationVesselName,
                        true,
                    ),
                );
                addAction(
                    actions,
                    openAssignmentAction(
                        assignmentId,
                        'Open Current Assignment',
                        'Review the active onboard mobilisation.',
                        'secondary',
                    ),
                );
            } else {
                addAction(
                    actions,
                    openAssignmentAction(
                        assignmentId,
                        'Open Current Assignment',
                        transferRecommended
                            ? 'Transfer Vessel is available from the current assignment when permitted.'
                            : 'Review the active onboard mobilisation.',
                    ),
                );
            }

            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Currently On Vessel',
                description: onVesselDescription,
                guidance:
                    'Another active Crew Assignment cannot be started while this mobilisation remains active.',
                severity: destinationAlert ? 'conflict' : 'attention',
                actions,
                intentOptions: [
                    {
                        question: 'Continuing the current vessel assignment?',
                        hint: 'Open the current assignment to continue normal movement.',
                        actionKey: 'open_assignment',
                    },
                    {
                        question: 'Moving directly to another vessel?',
                        hint: canTransfer
                            ? 'Use Transfer Vessel from the current assignment.'
                            : 'Transfer Vessel is available from the current assignment when permitted.',
                        actionKey: 'transfer_vessel',
                    },
                    {
                        question:
                            'Preparing work that happens after this assignment?',
                        hint: 'Use Crew Planning for future intention only.',
                        actionKey: 'plan_future',
                    },
                ],
                attention: {
                    title: 'Operational conflict risk',
                    message:
                        'Starting another active assignment could create conflicting vessel, payroll, sea-service and crew movement records.',
                },
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };
        }

        case 'demob_standby':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );
            addAction(actions, {
                key: 'return_home',
                label: 'Return Home',
                description:
                    'Record return home from the current assignment when movement is permitted.',
                kind: 'link',
                emphasis: 'primary',
            });
            addAction(actions, {
                key: 'redeploy',
                label: 'Redeploy',
                description:
                    'Start linked redeployment from the current assignment when movement is permitted.',
                kind: 'link',
                emphasis: 'secondary',
            });
            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Demobilisation Standby',
                description:
                    'This employee has disembarked and is waiting for onward travel or the next operational instruction.',
                guidance:
                    'Going home? Continue the current assignment and use Return Home. Going directly into another mobilisation? Use Redeploy. Only preparing work for later? Use Crew Planning.',
                severity: 'attention',
                actions,
                intentOptions: [
                    {
                        question: 'Going home?',
                        hint: 'Continue the current assignment and use Return Home.',
                        actionKey: 'return_home',
                    },
                    {
                        question: 'Going directly into another mobilisation?',
                        hint: 'Use Redeploy from the current assignment.',
                        actionKey: 'redeploy',
                    },
                    {
                        question: 'Only preparing work for later?',
                        hint: 'Use Crew Planning without starting another active assignment.',
                        actionKey: 'plan_future',
                    },
                ],
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        case 'home_redeploy':
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );
            addAction(actions, {
                key: 'close_assignment',
                label: 'Close Assignment',
                description:
                    'Close the current cycle when the vessel portion is complete.',
                kind: 'link',
                emphasis: 'secondary',
            });
            addAction(actions, {
                key: 'redeploy',
                label: 'Redeploy',
                description:
                    'Start another mobilisation through the existing Redeploy workflow.',
                kind: 'link',
                emphasis: 'primary',
            });
            addAction(actions, planFutureAction(permissions));

            return {
                title: 'Home / Redeployment',
                description:
                    'This employee has completed the vessel portion of the current mobilisation and is in the final assignment stage.',
                guidance:
                    'If the current cycle is complete, close the assignment. If another mobilisation starts now, use Redeploy. If the next job is only planned for later, use Crew Planning.',
                severity: 'attention',
                actions,
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
            };

        default:
            addAction(
                actions,
                continueAssignmentAction(assignmentId, assignmentNo),
            );
            addAction(actions, planFutureAction(permissions));

            return {
                title: status.label || 'Active Crew Assignment',
                description:
                    'This employee already has an active Crew Assignment.',
                guidance:
                    'Continue the existing mobilisation instead of starting another active assignment.',
                severity: 'attention',
                actions,
                showWhyBlocked: true,
                destinationAlert,
                plannedDateAlert,
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

export function readinessAlertClassName(
    severity: ReadinessAlert['severity'],
): string {
    return severity === 'conflict'
        ? readinessSeverityClassName('conflict')
        : readinessSeverityClassName('attention');
}
