const JOINING_FIELD_ERROR_KEYS = [
    'expected_joining_date',
    'joining_readiness_status',
    'joining_readiness_notes',
    'joining_blocker_notes',
    'actual_joining_date',
    'notes',
    'reason',
] as const;

const JOINING_KNOWN_GENERAL_KEYS = [
    'lock_version',
    'candidate',
    'stage',
    'expected_stage',
    'offer',
    'readiness',
    'general',
    'error',
] as const;

export type JoiningFormErrors = Record<string, string | string[] | undefined>;

function firstMessage(value: string | string[] | undefined): string | null {
    if (Array.isArray(value)) {
        const message = value[0]?.trim();

        return message ? message : null;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        return value.trim();
    }

    return null;
}

/** Messages for known concurrency, stage, offer, readiness, or general errors shown above the form. */
export function joiningKnownGeneralError(
    errors: JoiningFormErrors,
    priorityKeys: readonly string[] = JOINING_KNOWN_GENERAL_KEYS,
): string | null {
    for (const key of priorityKeys) {
        const message = firstMessage(errors[key]);

        if (message) {
            return message;
        }
    }

    return null;
}

/**
 * Errors that are not rendered beside a specific field and are not in known general keys,
 * rendered in an accessible fallback alert.
 */
export function joiningUnrenderedErrors(
    errors: JoiningFormErrors,
    fieldKeys: readonly string[] = JOINING_FIELD_ERROR_KEYS,
    generalKeys: readonly string[] = JOINING_KNOWN_GENERAL_KEYS,
): string[] {
    const rendered = new Set<string>([...fieldKeys, ...generalKeys]);
    const messages: string[] = [];

    for (const [key, value] of Object.entries(errors)) {
        if (rendered.has(key)) {
            continue;
        }

        const message = firstMessage(value);

        if (message) {
            messages.push(message);
        }
    }

    return messages;
}

export function joiningFieldError(
    errors: JoiningFormErrors,
    key: string,
): string | undefined {
    return firstMessage(errors[key]) ?? undefined;
}
