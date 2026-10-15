import type { ActionImpactSeverity } from '@/components/action-impact-preview';
import type { CrewMovementAction } from '../types.ts';
import { CREW_PHASE_LABELS } from '../types.ts';

export type MovementImpactPreviewLevel = 'none' | 'light' | 'full';

export type MovementNextPhaseOption = {
    value: string;
    label: string;
    description: string;
};

export type MovementActionConfig = {
    title: string;
    description: string;
    occurredAtLabel: string | null;
    submitLabel: string;
    impactTitle: string;
    impactDescription: string | string[];
    impactPreview: MovementImpactPreviewLevel;
    impactSeverity: ActionImpactSeverity;
    destructive?: boolean;
    fixedNextPhase?: string;
    nextPhaseLabel?: string;
    nextPhaseOptions?: MovementNextPhaseOption[];
    completionIntentLabel?: string;
    completionIntentOptions?: MovementNextPhaseOption[];
    keepOpenLabel?: string;
};

const LIGHT_IMPACT_DEFAULTS = {
    impactPreview: 'light' as const,
    impactSeverity: 'normal' as const,
    impactTitle: 'What will happen',
};

const FULL_IMPACT_DEFAULTS = {
    impactPreview: 'full' as const,
    impactSeverity: 'high' as const,
    impactTitle: 'What will happen',
};

export const MOVEMENT_ACTION_CONFIG: Partial<
    Record<CrewMovementAction, MovementActionConfig>
> = {
    approve_mobilisation: {
        title: 'Start Assignment',
        description:
            'This activates the draft Pre-Arrival assignment so operational movement can begin.',
        occurredAtLabel: 'Started at',
        submitLabel: 'Start Assignment',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription:
            'The assignment becomes active Pre-Arrival at the selected time.',
    },
    record_arrival: {
        title: 'Record Arrival',
        description:
            "This records the crew member's actual arrival and starts Pre-Joining Standby.",
        occurredAtLabel: 'Arrival date and time',
        submitLabel: 'Record Arrival',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription:
            "This records the crew member's actual arrival and starts Pre-Joining Standby.",
        fixedNextPhase: 'p1a',
    },
    send_to_training: {
        title: 'Send to Training',
        description: 'This completes Pre-Joining Standby and starts Training.',
        occurredAtLabel: 'Training started at',
        submitLabel: 'Send to Training',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription:
            'This completes Pre-Joining Standby and starts Training.',
        fixedNextPhase: 'p1b',
    },
    complete_training: {
        title: 'Complete Training',
        description:
            'Record training completion and return the employee to Pre-Joining Standby.',
        occurredAtLabel: 'Training completed at',
        submitLabel: 'Complete Training',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription:
            'This completes Training and returns the employee to Pre-Joining Standby.',
        fixedNextPhase: 'p1a',
    },
    join_vessel: {
        title: 'Join Vessel',
        description:
            'Record the actual join time and vessel details. Planned sign-off is optional and is not an actual disembarkation.',
        occurredAtLabel: 'Actual join date and time',
        submitLabel: 'Confirm Join',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription: [`This will start: ${CREW_PHASE_LABELS.p2}`],
        fixedNextPhase: 'p2',
    },
    plan_signoff: {
        title: 'Plan Sign-Off',
        description:
            'The employee remains On Vessel. This updates the linked Planning bar but does not record an actual disembarkation.',
        occurredAtLabel: null,
        submitLabel: 'Plan Sign-Off',
        impactTitle: 'What this does',
        impactDescription: '',
        impactPreview: 'none',
        impactSeverity: 'normal',
        fixedNextPhase: 'p2',
    },
    confirm_disembarkation: {
        title: 'Confirm Disembarkation',
        description:
            'Record the actual disembarkation and choose the next demobilisation phase. Planned Sign-Off does not disembark the employee.',
        occurredAtLabel: 'Actual disembarkation date and time',
        submitLabel: 'Confirm Disembarkation',
        ...FULL_IMPACT_DEFAULTS,
        impactDescription: [
            'On Vessel ends at the selected movement time.',
            'Actual disembarkation is recorded at the selected movement time.',
            'Sea service is finalized according to existing logic.',
            'The assignment moves into Demobilisation Standby or Home / Redeployment.',
        ],
        nextPhaseLabel: 'After disembarkation',
        nextPhaseOptions: [
            {
                value: 'p3',
                label: CREW_PHASE_LABELS.p3,
                description:
                    'The employee has left the vessel but is waiting before travelling home.',
            },
            {
                value: 'p4',
                label: CREW_PHASE_LABELS.p4,
                description:
                    'Skip demobilisation standby and proceed directly to home or redeployment.',
            },
        ],
    },
    start_demob_standby: {
        title: 'Start Demobilisation Standby',
        description:
            'Record the actual disembarkation and start demobilisation standby.',
        occurredAtLabel: 'Actual disembarkation date and time',
        submitLabel: 'Start Demobilisation Standby',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription:
            'This ends On Vessel and starts Demobilisation Standby.',
        fixedNextPhase: 'p3',
    },
    travel_home: {
        title: 'Return Home',
        description:
            'Record when the crew member returned home and choose whether this mobilisation cycle is complete.',
        occurredAtLabel: 'Date & Time',
        submitLabel: 'Return Home & Close Assignment',
        ...FULL_IMPACT_DEFAULTS,
        impactDescription: [
            'Demobilisation standby ends at the selected movement time.',
            'Home / Redeployment stage begins using the actual return-home timestamp.',
            'By default, the assignment closes and home availability tracking can begin according to existing rules.',
        ],
        completionIntentLabel: 'What happens next?',
        completionIntentOptions: [
            {
                value: 'close',
                label: 'Returned Home — finish this assignment',
                description:
                    'The crew member has returned home and this mobilisation cycle is complete.',
            },
            {
                value: 'redeploy',
                label: 'Keep open for Redeployment',
                description:
                    'Keep this assignment active in Home / Redeployment because the crew member may be redeployed.',
            },
        ],
        fixedNextPhase: 'p4',
    },
    close_assignment: {
        title: 'Close Assignment',
        description:
            'Closing completes this mobilisation cycle. No further standard movement actions will be available.',
        occurredAtLabel: 'Assignment closed at',
        submitLabel: 'Close Assignment',
        ...FULL_IMPACT_DEFAULTS,
        impactDescription: [
            'The assignment becomes Completed and this mobilisation cycle ends.',
            'No current active mobilisation remains on this assignment record.',
            'Historical movement data remains preserved.',
            'The employee becomes available according to normal status rules when no other active assignment exists.',
        ],
    },
    cancel_assignment: {
        title: 'Cancel Assignment',
        description:
            'You are about to cancel this mobilisation. Movement history already recorded remains preserved.',
        occurredAtLabel: 'Cancellation effective at',
        submitLabel: 'Cancel Assignment',
        impactPreview: 'full',
        impactSeverity: 'destructive',
        impactTitle: 'This may affect',
        impactDescription: [
            'Operational follow-up and planning links for this mobilisation.',
            'The assignment state, which becomes Cancelled when permitted for the current phase.',
            'Historical movement and sea-service data already recorded remain preserved.',
            'Active On Vessel assignments cannot be cancelled directly.',
        ],
        destructive: true,
        keepOpenLabel: 'Keep Assignment',
    },
    transfer_vessel: {
        title: 'Transfer Vessel',
        description:
            'Complete the current On Vessel assignment and start a linked assignment directly On Vessel on the destination vessel. No standby or travel phases are invented.',
        occurredAtLabel: 'Actual transfer date and time',
        submitLabel: 'Confirm Transfer',
        ...FULL_IMPACT_DEFAULTS,
        impactDescription: [
            'The current On Vessel phase ends at the selected movement time.',
            'Current assignment history on the source vessel remains preserved.',
            'Sea service for the completed source phase is synced according to existing logic.',
            'A linked destination assignment is created and started On Vessel on the destination vessel.',
            'The movement time becomes the operational boundary between source and destination.',
        ],
        fixedNextPhase: 'p2',
    },
    redeploy: {
        title: 'Redeploy',
        description:
            'Complete the current demobilisation or home phase and start a linked assignment at the chosen real starting phase. Earlier phases are not invented.',
        occurredAtLabel: 'Actual redeployment date and time',
        submitLabel: 'Confirm Redeploy',
        ...FULL_IMPACT_DEFAULTS,
        impactDescription: [
            'The current mobilisation is completed according to existing Redeploy logic.',
            'A linked destination assignment is created and remains connected to the source history.',
            'The destination starts only in the phase selected by the existing workflow (P0, P1A, or P2).',
            'No earlier phases are invented on the destination assignment.',
        ],
    },
};

function lightRecordArrivalConfig(
    impactDescription: string,
): MovementActionConfig {
    return {
        title: 'Record Arrival',
        description:
            "This records the crew member's actual arrival and starts Pre-Joining Standby.",
        occurredAtLabel: 'Arrival date and time',
        submitLabel: 'Record Arrival',
        ...LIGHT_IMPACT_DEFAULTS,
        impactDescription,
        fixedNextPhase: 'p1a',
    };
}

export function getMovementActionConfig(
    action: CrewMovementAction,
    currentPhase?: string | null,
): MovementActionConfig {
    if (action === 'record_arrival' && currentPhase === 'p0') {
        return lightRecordArrivalConfig(
            "This records the crew member's actual arrival and transitions the assignment from Pre-Arrival into Pre-Joining Standby.",
        );
    }

    return (
        MOVEMENT_ACTION_CONFIG[action] ?? {
            title: action,
            description: 'Record this crew movement action for the assignment.',
            occurredAtLabel: 'Date and time',
            submitLabel: action,
            impactTitle: 'What this does',
            impactDescription:
                'Record this crew movement action for the assignment.',
            impactPreview: 'light',
            impactSeverity: 'normal',
        }
    );
}
