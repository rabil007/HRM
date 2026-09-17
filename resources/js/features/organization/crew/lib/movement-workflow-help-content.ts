export const WORKFLOW_HELP = {
    transfer:
        'Currently onboard → move directly to another vessel without starting a separate active assignment.',
    redeploy:
        'After disembarkation or the final stage → begin the next linked mobilisation.',
    new_assignment:
        'No active mobilisation exists — start a fresh Crew Assignment cycle.',
    plan_future:
        'Schedule future work in Crew Planning without starting operational movement.',
} as const;

export type MovementWorkflowHelpTopic = keyof typeof WORKFLOW_HELP;

export function movementWorkflowHelpTopicForAction(
    actionKey: string,
): MovementWorkflowHelpTopic | null {
    switch (actionKey) {
        case 'transfer_vessel':
            return 'transfer';
        case 'redeploy':
            return 'redeploy';
        case 'plan_future':
            return 'plan_future';
        default:
            return null;
    }
}
