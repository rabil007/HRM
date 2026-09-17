import type { CrewMovementAction } from '../types.ts';
import { CREW_PHASE_LABELS } from '../types.ts';

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
    destructive?: boolean;
    fixedNextPhase?: string;
    nextPhaseLabel?: string;
    nextPhaseOptions?: MovementNextPhaseOption[];
    completionIntentLabel?: string;
    completionIntentOptions?: MovementNextPhaseOption[];
    keepOpenLabel?: string;
};

export const MOVEMENT_ACTION_CONFIG: Partial<
    Record<CrewMovementAction, MovementActionConfig>
> = {
    approve_mobilisation: {
        title: 'Start Assignment',
        description:
            'This activates the draft Pre-Mobilisation assignment so operational movement can begin.',
        occurredAtLabel: 'Started at',
        submitLabel: 'Start Assignment',
        impactTitle: 'What this does',
        impactDescription:
            'This activates the draft Pre-Mobilisation assignment. The employee enters active Pre-Mobilisation.',
    },
    record_arrival: {
        title: 'Record Arrival',
        description:
            "This records the crew member's actual arrival and starts Join Standby.",
        occurredAtLabel: 'Arrival date and time',
        submitLabel: 'Record Arrival',
        impactTitle: 'What this does',
        impactDescription:
            "This records the crew member's actual arrival and starts Join Standby.",
        fixedNextPhase: 'p2a',
    },
    send_to_training: {
        title: 'Send to Training',
        description: 'This completes Join Standby and starts P2B Training.',
        occurredAtLabel: 'Training started at',
        submitLabel: 'Send to Training',
        impactTitle: 'What this does',
        impactDescription:
            'This completes Join Standby and starts P2B Training.',
        fixedNextPhase: 'p2b',
    },
    complete_training: {
        title: 'Complete Training',
        description:
            'Record training completion and return the employee to Join Standby.',
        occurredAtLabel: 'Training completed at',
        submitLabel: 'Complete Training',
        impactTitle: 'What this does',
        impactDescription:
            'This completes P2B Training and returns the employee to Join Standby.',
        fixedNextPhase: 'p2a',
    },
    mark_ready: {
        title: 'Mark Ready',
        description:
            'This completes Join Standby and moves the employee to P3 Ready to Join. The next operational action will be Join Vessel.',
        occurredAtLabel: 'Ready from',
        submitLabel: 'Mark Ready',
        impactTitle: 'What this does',
        impactDescription:
            'This moves the employee from P2A Join Standby to P3 Ready to Join.',
        fixedNextPhase: 'p3',
    },
    join_vessel: {
        title: 'Join Vessel',
        description:
            'Record the actual join time and vessel details. Planned sign-off is optional and is not an actual disembarkation.',
        occurredAtLabel: 'Actual join date and time',
        submitLabel: 'Join Vessel',
        impactTitle: 'This action will',
        impactDescription: [
            'Move the employee to P4 On Vessel.',
            'Mark the employee as onboard in Crew Assignments.',
            'Create or update the linked Planning Gantt bar.',
            'Use the actual join date as the Planning start date.',
            'Sea Service is created only after actual disembarkation.',
        ],
        fixedNextPhase: 'p4',
    },
    plan_signoff: {
        title: 'Plan Sign-Off',
        description:
            'The employee remains in P4 On Vessel. This updates the linked Planning bar but does not record an actual disembarkation.',
        occurredAtLabel: null,
        submitLabel: 'Plan Sign-Off',
        impactTitle: 'What this does',
        impactDescription:
            'The employee remains in P4 On Vessel. This updates the linked Planning bar but does not record an actual disembarkation.',
        fixedNextPhase: 'p4',
    },
    confirm_disembarkation: {
        title: 'Confirm Disembarkation',
        description:
            'Record the actual disembarkation and choose the next demobilisation phase. Planned Sign-Off does not disembark the employee.',
        occurredAtLabel: 'Actual disembarkation date and time',
        submitLabel: 'Confirm Disembarkation',
        impactTitle: 'What will happen',
        impactDescription: [
            'P4 On Vessel ends at the selected movement time.',
            'Sea service is finalized according to existing logic.',
            'The assignment moves into the next configured movement stage (P5 or P6).',
            'Planned Sign-Off alone does not disembark the crew member.',
        ],
        nextPhaseLabel: 'After disembarkation',
        nextPhaseOptions: [
            {
                value: 'p5',
                label: CREW_PHASE_LABELS.p5,
                description:
                    'The employee has left the vessel but is waiting before travelling home.',
            },
            {
                value: 'p6',
                label: 'Home / Redeploy',
                description:
                    'Skip demobilisation standby and proceed directly to home or redeployment.',
            },
        ],
    },
    travel_home: {
        title: 'Return Home',
        description:
            'Record when the crew member returned home and choose whether this mobilisation cycle is complete.',
        occurredAtLabel: 'Date & Time',
        submitLabel: 'Return Home & Close Assignment',
        impactTitle: 'What will happen',
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
        fixedNextPhase: 'p6',
    },
    close_assignment: {
        title: 'Close Assignment',
        description:
            'Closing completes this mobilisation cycle. No further standard movement actions will be available.',
        occurredAtLabel: 'Assignment closed at',
        submitLabel: 'Complete Assignment',
        impactTitle: 'What will happen',
        impactDescription: [
            'The assignment becomes Completed and this mobilisation cycle ends.',
            'No current active mobilisation remains on this assignment record.',
            'The employee becomes available according to normal status rules when no other active assignment exists.',
        ],
    },
    cancel_assignment: {
        title: 'Cancel Assignment',
        description:
            'Use only when the mobilisation should be stopped. Movement history already recorded remains preserved.',
        occurredAtLabel: 'Cancellation effective at',
        submitLabel: 'Cancel Assignment',
        impactTitle: 'What will happen',
        impactDescription: [
            'The assignment is marked Cancelled when permitted for the current phase.',
            'Historical movement and sea-service data already recorded remain preserved.',
            'Before P4, the linked future Planning bar is removed according to existing rules.',
            'Active P4 On Vessel assignments cannot be cancelled directly.',
        ],
        destructive: true,
        keepOpenLabel: 'Keep Assignment',
    },
    transfer_vessel: {
        title: 'Transfer Vessel',
        description:
            'Complete the current On Vessel assignment and start a linked assignment directly in P4 on the destination vessel. No standby or travel phases are invented.',
        occurredAtLabel: 'Actual transfer date and time',
        submitLabel: 'Transfer Vessel',
        impactTitle: 'What will happen',
        impactDescription: [
            'The current P4 On Vessel phase ends at the selected movement time.',
            'Current assignment history on the source vessel remains preserved.',
            'Sea service for the completed source P4 is synced according to existing logic.',
            'A linked destination assignment is created and started in P4 on the destination vessel.',
            'The movement time becomes the operational boundary between source and destination.',
        ],
        fixedNextPhase: 'p4',
    },
    redeploy: {
        title: 'Redeploy',
        description:
            'Complete the current demobilisation or home phase and start a linked assignment at the chosen real starting phase. Earlier phases are not invented.',
        occurredAtLabel: 'Actual redeployment date and time',
        submitLabel: 'Redeploy',
        impactTitle: 'What will happen',
        impactDescription: [
            'The current mobilisation is completed according to existing Redeploy logic.',
            'A linked destination assignment is created and remains connected to the source history.',
            'The destination starts only in the phase selected by the existing workflow (P0, P2A, or P4).',
            'No earlier phases are invented on the destination assignment.',
        ],
    },
};

export function getMovementActionConfig(
    action: CrewMovementAction,
    currentPhase?: string | null,
): MovementActionConfig {
    if (action === 'record_arrival' && currentPhase === 'p0') {
        return {
            title: 'Record Arrival',
            description:
                "This records the crew member's actual arrival and starts Join Standby.",
            occurredAtLabel: 'Arrival date and time',
            submitLabel: 'Record Arrival',
            impactTitle: 'What this does',
            impactDescription:
                "This records the crew member's actual arrival and transitions the assignment from Pre-Mobilisation into Join Standby.",
            fixedNextPhase: 'p2a',
        };
    }

    if (action === 'record_arrival' && currentPhase === 'p1') {
        return {
            title: 'Record Arrival',
            description:
                "This records the crew member's actual arrival and starts Join Standby.",
            occurredAtLabel: 'Arrival date and time',
            submitLabel: 'Record Arrival',
            impactTitle: 'What this does',
            impactDescription:
                'This completes Travel In and moves the employee into Join Standby.',
            fixedNextPhase: 'p2a',
        };
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
        }
    );
}
