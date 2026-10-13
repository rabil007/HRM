export type CrewAssignmentStartingStage =
    | 'draft'
    | 'pre_mobilisation'
    | 'already_arrived';

export const STARTING_STAGE_REQUIRED_MESSAGE =
    'Select how this assignment should start before continuing.';

const STARTING_STAGE_OPTIONS: Record<
    CrewAssignmentStartingStage,
    { label: string; description: string }
> = {
    draft: {
        label: 'Save as Draft',
        description: 'Save this assignment without starting crew movement.',
    },
    pre_mobilisation: {
        label: 'Start Pre-Mobilisation',
        description: 'Start the assignment before the employee arrives.',
    },
    already_arrived: {
        label: 'Already Arrived',
        description:
            'The employee has already arrived. Start directly in Join Standby.',
    },
};

export function availableStartingStages(options: {
    canCreate: boolean;
    canStart: boolean;
}): CrewAssignmentStartingStage[] {
    if (!options.canCreate) {
        return [];
    }

    const stages: CrewAssignmentStartingStage[] = ['draft'];

    if (options.canStart) {
        stages.push('pre_mobilisation', 'already_arrived');
    }

    return stages;
}

export function startingStageOptions(
    stages: CrewAssignmentStartingStage[],
): Array<{
    value: CrewAssignmentStartingStage;
    label: string;
    description: string;
}> {
    return stages.map((value) => ({
        value,
        ...STARTING_STAGE_OPTIONS[value],
    }));
}

export function startingStageRequiresActualArrival(
    stage: CrewAssignmentStartingStage | null,
): boolean {
    return stage === 'already_arrived';
}

export function startingStageToSubmissionIntent(
    stage: CrewAssignmentStartingStage,
): 'start' | 'draft' {
    return stage === 'draft' ? 'draft' : 'start';
}

export function resolveStartingStageSubmitLabel(
    stage: CrewAssignmentStartingStage | null,
): string {
    switch (stage) {
        case 'draft':
            return 'Save as Draft';
        case 'pre_mobilisation':
        case 'already_arrived':
            return 'Create & Start';
        default:
            return 'Continue';
    }
}
