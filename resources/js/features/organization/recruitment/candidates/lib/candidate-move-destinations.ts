import type {
    CandidateIndexRow,
    CandidateMoveDestination,
    CandidateStage,
} from '../types';

export function actionableDestinations(
    candidate: CandidateIndexRow,
): CandidateMoveDestination[] {
    return (candidate.move_destinations ?? []).filter(
        (destination) =>
            destination.kind !== 'blocked' &&
            destination.kind !== 'select_required',
    );
}

export function destinationForStage(
    candidate: CandidateIndexRow,
    stage: CandidateStage | string,
): CandidateMoveDestination | null {
    return (
        (candidate.move_destinations ?? []).find(
            (destination) => destination.stage === stage,
        ) ?? null
    );
}

export function isDropAllowed(
    candidate: CandidateIndexRow,
    stage: CandidateStage | string,
): boolean {
    const destination = destinationForStage(candidate, stage);

    if (!destination) {
        return false;
    }

    return (
        destination.kind !== 'blocked' && destination.kind !== 'select_required'
    );
}
