const INTERVIEW_FIELD_ERROR_KEYS = [
    'interview_scheduled_at',
    'interview_mode',
    'interviewer_user_id',
    'external_interviewer_name',
    'interview_location',
    'interview_feedback',
] as const;

const INTERVIEW_KNOWN_GENERAL_KEYS = [
    'lock_version',
    'candidate',
    'stage',
    'expected_stage',
] as const;

export type InterviewFormErrors = Record<string, string | string[] | undefined>;

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

/** Messages for known concurrency / ownership keys shown above the form. */
export function interviewKnownGeneralError(
    errors: InterviewFormErrors,
): string | null {
    for (const key of INTERVIEW_KNOWN_GENERAL_KEYS) {
        const message = firstMessage(errors[key]);

        if (message) {
            return message;
        }
    }

    return null;
}

/**
 * Errors that are not rendered beside a specific field and are not already
 * covered by the known general keys — shown in an accessible fallback region.
 */
export function interviewUnrenderedErrors(
    errors: InterviewFormErrors,
): string[] {
    const rendered = new Set<string>([
        ...INTERVIEW_FIELD_ERROR_KEYS,
        ...INTERVIEW_KNOWN_GENERAL_KEYS,
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

export function interviewFieldError(
    errors: InterviewFormErrors,
    key: (typeof INTERVIEW_FIELD_ERROR_KEYS)[number],
): string | undefined {
    return firstMessage(errors[key]) ?? undefined;
}
