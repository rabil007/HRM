import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
} from '../types';
import type { PlannedSignoffChoice } from './tour-of-duty';

export type CrewPositionTourOption =
    CrewAssignmentFormOptions['positions'][number];

/** @deprecated use CrewPositionTourOption */
export type CrewRankTourOption = CrewPositionTourOption;

export function positionHasResolvedTour(
    position: CrewPositionTourOption | undefined,
): boolean {
    const days =
        position?.max_tour_of_duty_days ?? position?.resolved_tour_of_duty_days;

    return days != null && days > 0;
}

/** @deprecated use positionHasResolvedTour */
export const rankHasResolvedTour = positionHasResolvedTour;

/** Default sign-off choice for a new destination assignment (no existing_plan). */
export function defaultDestinationTourSignoffChoice(
    position: CrewPositionTourOption | undefined,
): Exclude<PlannedSignoffChoice, 'existing_plan'> {
    return positionHasResolvedTour(position)
        ? 'tour_of_duty'
        : 'manual_override';
}

export function findPositionTourOption(
    positions: CrewPositionTourOption[] | undefined,
    positionId: number | null | undefined,
): CrewPositionTourOption | undefined {
    if (positionId == null || !positions) {
        return undefined;
    }

    return positions.find((position) => position.id === positionId);
}

/** @deprecated use findPositionTourOption */
export const findRankTourOption = findPositionTourOption;

/**
 * When destination position changes, prefer Tour when available without wiping a
 * deliberate manual override the user has started filling in.
 */
export function nextSignoffChoiceForPositionChange(params: {
    previousChoice: PlannedSignoffChoice;
    nextPosition: CrewPositionTourOption | undefined;
    hasManualOverrideInput: boolean;
}): Exclude<PlannedSignoffChoice, 'existing_plan'> {
    const nextHasTour = positionHasResolvedTour(params.nextPosition);

    if (!nextHasTour) {
        return 'manual_override';
    }

    if (
        params.previousChoice === 'manual_override' &&
        params.hasManualOverrideInput
    ) {
        return 'manual_override';
    }

    return 'tour_of_duty';
}

/** @deprecated use nextSignoffChoiceForPositionChange */
export function nextSignoffChoiceForRankChange(params: {
    previousChoice: PlannedSignoffChoice;
    nextRank?: CrewPositionTourOption | undefined;
    nextPosition?: CrewPositionTourOption | undefined;
    hasManualOverrideInput: boolean;
}): Exclude<PlannedSignoffChoice, 'existing_plan'> {
    return nextSignoffChoiceForPositionChange({
        previousChoice: params.previousChoice,
        nextPosition: params.nextPosition ?? params.nextRank,
        hasManualOverrideInput: params.hasManualOverrideInput,
    });
}

export function hasManualOverrideInput(data: {
    planned_signoff_at?: string;
    planned_signoff_override_reason?: string;
}): boolean {
    return Boolean(
        data.planned_signoff_at?.trim() ||
        data.planned_signoff_override_reason?.trim(),
    );
}

export function actionUsesDirectP4TourSignoff(
    action: CrewMovementAction,
    startingPhase?: string,
): boolean {
    if (action === 'join_vessel' || action === 'transfer_vessel') {
        return true;
    }

    return action === 'redeploy' && startingPhase === 'p4';
}

export function shouldShowDirectP4TourSignoffFields(
    action: CrewMovementAction,
    startingPhase?: string,
): boolean {
    return actionUsesDirectP4TourSignoff(action, startingPhase);
}

export type TourSignoffPayload = Partial<CrewMovementActionFormData> &
    Record<string, unknown>;

/**
 * Normalize Tour / Planned Sign-Off fields before POST.
 * Does not invent dates — backend remains authoritative.
 */
export function normalizeTourSignoffPayload(
    data: CrewMovementActionFormData,
    action: CrewMovementAction,
): TourSignoffPayload {
    const payload: TourSignoffPayload = { ...data };

    if (action === 'redeploy' && data.starting_phase !== 'p4') {
        delete payload.planned_signoff_choice;
        delete payload.planned_signoff_override_reason;

        if (data.starting_phase === 'p0') {
            payload.planned_signoff_at = '';
            payload.vessel_id = null;
            payload.position_id = null;
            payload.client_id = null;
        }

        return payload;
    }

    if (!actionUsesDirectP4TourSignoff(action, data.starting_phase)) {
        return payload;
    }

    if (data.planned_signoff_choice !== 'manual_override') {
        delete payload.planned_signoff_at;
        payload.planned_signoff_override_reason = '';
    }

    return payload;
}

export function clearedDirectP4TourFields(): Pick<
    CrewMovementActionFormData,
    'planned_signoff_choice' | 'planned_signoff_override_reason'
> {
    return {
        planned_signoff_choice: 'manual_override',
        planned_signoff_override_reason: '',
    };
}
