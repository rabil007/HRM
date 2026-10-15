import { getMovementActionConfig } from '../actions/movement-action-config.ts';
import type { CrewMovementAction } from '../types.ts';

/**
 * Initial next_phase for a movement dialog.
 * Uses the action config only. Do not special-case retired codes (p2a/p5/p6).
 */
export function resolveInitialMovementNextPhase(
    action: CrewMovementAction,
    currentPhaseCode: string | null | undefined,
): string {
    const config = getMovementActionConfig(action, currentPhaseCode);

    return config.nextPhaseOptions?.[0]?.value ?? config.fixedNextPhase ?? '';
}

/** Direct Home / Redeployment disembarkation does not create a post-sign-off hotel stay. */
export function confirmDisembarkationOmitsAccommodation(
    nextPhase: string,
): boolean {
    return nextPhase === 'p4';
}

/** Destination pre-join accommodation is captured only when redeploy starts at P1A. */
export function redeployCapturesDestinationAccommodation(
    startingPhase: string,
): boolean {
    return startingPhase === 'p1a';
}
