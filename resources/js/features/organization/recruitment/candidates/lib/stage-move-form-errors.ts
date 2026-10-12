const FIELD_ERROR_KEYS = [
    'reason',
    'to_stage',
    'interview_scheduled_at',
    'interview_mode',
    'interviewer_user_id',
    'external_interviewer_name',
    'interview_location',
    'interview_feedback',
    'actual_joining_date',
    'notes',
] as const;

const KNOWN_GENERAL_KEYS = [
    'candidate',
    'lock_version',
    'stage',
    'expected_stage',
    'expected_outcome',
    'interview_outcome',
    'offer',
    'readiness_status',
] as const;

export type StageMoveFormErrors = Record<string, string | string[] | undefined>;

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

export function stageMoveFieldError(
    errors: StageMoveFormErrors,
    key: (typeof FIELD_ERROR_KEYS)[number],
): string | undefined {
    return firstMessage(errors[key]) ?? undefined;
}

export function stageMoveKnownGeneralError(
    errors: StageMoveFormErrors,
): string | null {
    for (const key of KNOWN_GENERAL_KEYS) {
        const message = firstMessage(errors[key]);

        if (message) {
            return message;
        }
    }

    return null;
}

/** Errors not already shown beside a field or in the known general banner. */
export function stageMoveUnrenderedErrors(
    errors: StageMoveFormErrors,
): string[] {
    const rendered = new Set<string>([
        ...FIELD_ERROR_KEYS,
        ...KNOWN_GENERAL_KEYS,
    ]);
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
