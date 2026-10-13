/**
 * Optional Start Assignment shortcut: skip Pre-Mobilisation and open at P2A.
 * Backend Store/Bulk requests remain authoritative.
 */

export const ALREADY_ARRIVED_LABEL = 'Already Arrived — Skip Pre-Mobilisation';

export const ALREADY_ARRIVED_EXPLANATION =
    'The crew member has already arrived. The assignment will start directly in Join Standby (P2A), skipping Pre-Mobilisation.';

export const ALREADY_ARRIVED_EXPLANATION_AFTER_PLANNING =
    'The crew member has already arrived. Enter the Actual Arrival Date below — the planned arrival from Crew Planning is kept separately and is not used as the actual date.';

export const ACTUAL_ARRIVAL_DATE_LABEL = 'Actual Arrival Date';

export const ARRIVAL_DATE_OPTIONAL_LABEL = 'Arrival Date';

export const ACTUAL_ARRIVAL_DATE_HELPER =
    'Date the crew member actually arrived at the joining location.';

export const ACTUAL_ARRIVAL_DATE_HELPER_AFTER_PLANNING =
    'Enter the date the crew member actually arrived. The planned arrival from Crew Planning stays separate and is not reused as the actual date.';

export const EXPECTED_ARRIVAL_DATE_HELPER =
    'Expected date the crew member will arrive at the joining location. Actual arrival is recorded later through Record Arrival.';

export const ACTUAL_ARRIVAL_REQUIRED_MESSAGE =
    'Actual Arrival Date is required when Already Arrived is selected.';

export const ACTUAL_ARRIVAL_FUTURE_MESSAGE =
    'Actual Arrival Date cannot be in the future.';

export function normalizeArrivalDate(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '';
    }

    return value.slice(0, 10);
}

/**
 * When enabling Already Arrived, clear a date that still matches the Crew
 * Planning expected arrival so the operator must enter Actual Arrival Date
 * explicitly. When disabling with an empty field, restore the planning prefill.
 */
export function resolveArrivalAfterAlreadyArrivedToggle(options: {
    enabling: boolean;
    currentArrival: string | null | undefined;
    planningPrefillArrival: string | null | undefined;
}): string | null {
    const current = normalizeArrivalDate(options.currentArrival);
    const prefill = normalizeArrivalDate(options.planningPrefillArrival);

    if (options.enabling) {
        if (prefill !== '' && current === prefill) {
            return null;
        }

        return current === '' ? null : current;
    }

    if (current === '' && prefill !== '') {
        return prefill;
    }

    return current === '' ? null : current;
}

export function alreadyArrivedExplanation(
    hasPlanningPrefillArrival: boolean,
): string {
    return hasPlanningPrefillArrival
        ? ALREADY_ARRIVED_EXPLANATION_AFTER_PLANNING
        : ALREADY_ARRIVED_EXPLANATION;
}

export function actualArrivalDateHelper(
    hasPlanningPrefillArrival: boolean,
): string {
    return hasPlanningPrefillArrival
        ? ACTUAL_ARRIVAL_DATE_HELPER_AFTER_PLANNING
        : ACTUAL_ARRIVAL_DATE_HELPER;
}
