export type CrewAssignmentSingleSubmitAction =
    | 'already_arrived'
    | 'pre_mobilisation'
    | 'draft';

export const CREW_ASSIGNMENT_CREATE_ACTION_TOOLTIPS: Record<
    CrewAssignmentSingleSubmitAction,
    string
> = {
    already_arrived:
        'Create an active assignment directly in Pre-Joining Standby (P1A). Actual arrival date required.',
    pre_mobilisation:
        'Activate the assignment in P0 before the employee arrives.',
    draft: 'Save the assignment without starting crew movement.',
};

export function resolveSingleSubmitRequest(
    action: CrewAssignmentSingleSubmitAction,
): {
    submissionIntent: 'start' | 'draft';
    alreadyArrived: boolean;
} {
    switch (action) {
        case 'draft':
            return { submissionIntent: 'draft', alreadyArrived: false };
        case 'pre_mobilisation':
            return { submissionIntent: 'start', alreadyArrived: false };
        case 'already_arrived':
            return { submissionIntent: 'start', alreadyArrived: true };
    }
}
