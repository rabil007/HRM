import type { ReactElement } from 'react';
import { MovementNextPhaseChoice } from '@/features/organization/crew/actions/movement-next-phase-choice';
import { startingStageOptions } from '@/features/organization/crew/lib/crew-assignment-starting-stage';
import type { CrewAssignmentStartingStage } from '@/features/organization/crew/lib/crew-assignment-starting-stage';

export function CrewAssignmentStartingStageSection({
    value,
    onChange,
    stages,
    error,
}: {
    value: CrewAssignmentStartingStage | '';
    onChange: (value: CrewAssignmentStartingStage) => void;
    stages: CrewAssignmentStartingStage[];
    error?: string;
}): ReactElement | null {
    if (stages.length === 0) {
        return null;
    }

    return (
        <MovementNextPhaseChoice
            id="crew-assignment-starting-stage"
            label="Starting Stage"
            value={value}
            options={startingStageOptions(stages)}
            onChange={(next) => onChange(next as CrewAssignmentStartingStage)}
            error={error}
        />
    );
}
