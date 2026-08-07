import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
} from '../types';
import type { PlannedSignoffChoice } from './tour-of-duty';

export type CrewRankTourOption = CrewAssignmentFormOptions['ranks'][number];

export function rankHasResolvedTour(
    rank: CrewRankTourOption | undefined,
): boolean {
    return (
        rank?.resolved_tour_of_duty_days != null &&
        rank.resolved_tour_of_duty_days > 0
    );
}

/** Default sign-off choice for a new destination assignment (no existing_plan). */
export function defaultDestinationTourSignoffChoice(
    rank: CrewRankTourOption | undefined,
): Exclude<PlannedSignoffChoice, 'existing_plan'> {
    return rankHasResolvedTour(rank) ? 'tour_of_duty' : 'manual_override';
}

export function findRankTourOption(
    ranks: CrewRankTourOption[] | undefined,
    rankId: number | null | undefined,
): CrewRankTourOption | undefined {
    if (rankId == null || !ranks) {
        return undefined;
    }

    return ranks.find((rank) => rank.id === rankId);
}

/**
 * When destination rank changes, prefer Tour when available without wiping a
 * deliberate manual override the user has started filling in.
 */
export function nextSignoffChoiceForRankChange(params: {
    previousChoice: PlannedSignoffChoice;
    nextRank: CrewRankTourOption | undefined;
    hasManualOverrideInput: boolean;
}): Exclude<PlannedSignoffChoice, 'existing_plan'> {
    const nextHasTour = rankHasResolvedTour(params.nextRank);

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
        delete payload.tour_of_duty_days;
        delete payload.planned_signoff_choice;
        delete payload.planned_signoff_override_reason;

        if (data.starting_phase === 'p0') {
            payload.planned_signoff_at = '';
            payload.vessel_id = null;
            payload.rank_id = null;
            payload.client_id = null;
            payload.company_visa_type_id = null;
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

    if (data.tour_of_duty_days === '' || data.tour_of_duty_days === null) {
        delete payload.tour_of_duty_days;
    }

    return payload;
}

export function clearedDirectP4TourFields(): Pick<
    CrewMovementActionFormData,
    | 'tour_of_duty_days'
    | 'planned_signoff_choice'
    | 'planned_signoff_override_reason'
> {
    return {
        tour_of_duty_days: '',
        planned_signoff_choice: 'manual_override',
        planned_signoff_override_reason: '',
    };
}
