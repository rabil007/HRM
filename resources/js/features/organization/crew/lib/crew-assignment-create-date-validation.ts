/**
 * Create-form date order helpers.
 * Messages match StoreCrewAssignmentRequest; backend remains authoritative on submit.
 */

export const ARRIVAL_AFTER_JOIN_MESSAGE =
    'Arrival Date cannot be after Expected Vessel Join.';

export const SIGNOFF_BEFORE_JOIN_MESSAGE =
    'Expected Sign-off cannot be before Expected Vessel Join.';

export function normalizeCreateDate(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '';
    }

    return value.slice(0, 10);
}

const CREATE_CALENDAR_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Returns a normalized YYYY-MM-DD when the value is a real calendar date.
 * Uses component-based parsing to avoid UTC shifts on date-only strings.
 */
export function parseValidCreateCalendarDate(
    value: string | null | undefined,
): string | null {
    const normalized = normalizeCreateDate(value);

    if (normalized === '') {
        return null;
    }

    const match = CREATE_CALENDAR_DATE_PATTERN.exec(normalized);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const probe = new Date(year, month - 1, day);

    if (
        probe.getFullYear() !== year ||
        probe.getMonth() !== month - 1 ||
        probe.getDate() !== day
    ) {
        return null;
    }

    return normalized;
}

export function arrivalAfterJoinMessage(
    arrival: string | null | undefined,
    join: string | null | undefined,
): string | null {
    const arrivalDate = normalizeCreateDate(arrival);
    const joinDate = normalizeCreateDate(join);

    if (!arrivalDate || !joinDate) {
        return null;
    }

    return arrivalDate > joinDate ? ARRIVAL_AFTER_JOIN_MESSAGE : null;
}

export function signoffBeforeJoinMessage(
    join: string | null | undefined,
    signoff: string | null | undefined,
): string | null {
    const joinDate = normalizeCreateDate(join);
    const signoffDate = normalizeCreateDate(signoff);

    if (!joinDate || !signoffDate) {
        return null;
    }

    return signoffDate < joinDate ? SIGNOFF_BEFORE_JOIN_MESSAGE : null;
}

/**
 * Prefer live client date-order feedback; drop stale server order errors when
 * the current values no longer violate the rule. Preserve unrelated server errors.
 */
export function resolveArrivalDateDisplayError(options: {
    serverError?: string;
    arrival: string | null | undefined;
    join: string | null | undefined;
}): string | undefined {
    const live = arrivalAfterJoinMessage(options.arrival, options.join);

    if (live) {
        return live;
    }

    if (options.serverError === ARRIVAL_AFTER_JOIN_MESSAGE) {
        return undefined;
    }

    return options.serverError;
}

export function resolveSignoffDateDisplayError(options: {
    serverError?: string;
    join: string | null | undefined;
    signoff: string | null | undefined;
}): string | undefined {
    const live = signoffBeforeJoinMessage(options.join, options.signoff);

    if (live) {
        return live;
    }

    if (options.serverError === SIGNOFF_BEFORE_JOIN_MESSAGE) {
        return undefined;
    }

    return options.serverError;
}

export type CreateDateField =
    | 'planned_arrival_at'
    | 'planned_join_at'
    | 'planned_signoff_at';

/**
 * Inertia error keys whose truth depends on the changed date field.
 */
export function dependentCreateDateErrorKeys(
    field: CreateDateField,
    options?: { crewRowCount?: number; crewIndex?: number },
): string[] {
    const keys: string[] = [];

    if (field === 'planned_arrival_at') {
        keys.push('planned_arrival_at');

        if (options?.crewIndex != null) {
            keys.push(`crew.${options.crewIndex}.planned_arrival_at`);
        }
    }

    if (field === 'planned_join_at') {
        keys.push(
            'planned_join_at',
            'planned_arrival_at',
            'planned_signoff_at',
        );

        const rowCount = options?.crewRowCount ?? 0;

        for (let index = 0; index < rowCount; index += 1) {
            keys.push(`crew.${index}.planned_arrival_at`);
        }
    }

    if (field === 'planned_signoff_at') {
        keys.push('planned_signoff_at');
    }

    return keys;
}

/**
 * Inertia `clearErrors` is typed to form keys; date helpers return plain strings.
 */
export function clearCreateFormDateErrors(
    clearErrors: (...fields: string[]) => void,
    keys: string[],
): void {
    if (keys.length === 0) {
        return;
    }

    clearErrors(...keys);
}
