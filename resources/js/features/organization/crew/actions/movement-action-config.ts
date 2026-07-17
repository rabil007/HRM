import type { CrewMovementAction } from '../types';
import { CREW_PHASE_LABELS } from '../types';

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
    keepOpenLabel?: string;
};

export const MOVEMENT_ACTION_CONFIG: Partial<
    Record<CrewMovementAction, MovementActionConfig>
> = {
    approve_mobilisation: {
        title: 'Approve Mobilisation',
        description:
            'This starts the mobilisation cycle and moves the employee to P1 Travel In.',
        occurredAtLabel: 'Mobilisation started at',
        submitLabel: 'Approve Mobilisation',
        impactTitle: 'What this does',
        impactDescription:
            'This starts the mobilisation cycle and moves the employee to P1 Travel In.',
        fixedNextPhase: 'p1',
    },
    record_arrival: {
        title: 'Record Arrival',
        description: 'This completes Travel In and records the arrival.',
        occurredAtLabel: 'Arrival date and time',
        submitLabel: 'Record Arrival',
        impactTitle: 'What this does',
        impactDescription: 'This completes Travel In.',
        nextPhaseLabel: 'After arrival',
        nextPhaseOptions: [
            {
                value: 'p2a',
                label: CREW_PHASE_LABELS.p2a,
                description:
                    'The employee has arrived but is waiting for the vessel or final clearance.',
            },
            {
                value: 'p3',
                label: CREW_PHASE_LABELS.p3,
                description:
                    'The employee has arrived and is fully cleared to join the vessel.',
            },
        ],
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
        description: 'Record training completion and choose the next phase.',
        occurredAtLabel: 'Training completed at',
        submitLabel: 'Complete Training',
        impactTitle: 'What this does',
        impactDescription: 'This completes P2B Training.',
        nextPhaseLabel: 'After training',
        nextPhaseOptions: [
            {
                value: 'p2a',
                label: 'Return to Join Standby',
                description:
                    'The employee will wait for vessel joining or further instructions.',
            },
            {
                value: 'p3',
                label: CREW_PHASE_LABELS.p3,
                description:
                    'Training is complete and the employee is cleared to join.',
            },
        ],
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
            'Mark the employee as onboard in Current Crew.',
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
            'Record the actual disembarkation and choose the next demobilisation phase.',
        occurredAtLabel: 'Actual disembarkation date and time',
        submitLabel: 'Confirm Disembarkation',
        impactTitle: 'This action will',
        impactDescription: [
            'Complete P4 On Vessel.',
            'End the Planning bar on the actual disembarkation date.',
            'Generate or update the employee Sea Service record.',
            'Move the employee to P5 or P6.',
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
        title: 'Travel Home',
        description:
            'This completes P5 Demobilisation Standby and moves the employee to P6 Home / Redeploy.',
        occurredAtLabel: 'Actual travel-home date and time',
        submitLabel: 'Travel Home',
        impactTitle: 'What this does',
        impactDescription:
            'This completes P5 Demobilisation Standby and moves the employee to P6 Home / Redeploy.',
        fixedNextPhase: 'p6',
    },
    close_assignment: {
        title: 'Close Assignment',
        description:
            'Closing completes this mobilisation cycle. No further standard movement actions will be available.',
        occurredAtLabel: 'Assignment closed at',
        submitLabel: 'Complete Assignment',
        impactTitle: 'What this does',
        impactDescription:
            'Closing completes this mobilisation cycle. No further standard movement actions will be available.',
    },
    cancel_assignment: {
        title: 'Cancel Assignment',
        description:
            'Before P4, the linked future Planning bar will be removed. Historical completed onboard service is preserved. An employee currently onboard cannot be cancelled directly.',
        occurredAtLabel: 'Cancellation effective at',
        submitLabel: 'Cancel Assignment',
        impactTitle: 'What this does',
        impactDescription: [
            'Before P4, the linked future Planning bar will be removed.',
            'Historical completed onboard service is preserved.',
            'An employee currently onboard cannot be cancelled directly.',
        ],
        destructive: true,
        keepOpenLabel: 'Keep Assignment',
    },
};

export function getMovementActionConfig(
    action: CrewMovementAction,
): MovementActionConfig {
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
