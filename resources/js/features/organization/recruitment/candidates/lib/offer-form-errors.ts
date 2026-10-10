const OFFER_FIELD_ERROR_KEYS = [
    'salary_amount',
    'salary_currency_code',
    'proposed_joining_date',
    'offer_date',
    'expiry_date',
    'offer_document',
    'acceptance_document',
    'notes',
    'sent_at',
    'accepted_at',
    'rejected_at',
    'reason',
] as const;

const OFFER_KNOWN_GENERAL_KEYS = [
    'lock_version',
    'offer_lock_version',
    'candidate',
    'offer',
    'offer_status',
    'stage',
    'expected_stage',
    'expected_offer_status',
    'expected_outcome',
    'general',
    'error',
] as const;

export type OfferFormErrors = Record<string, string | string[] | undefined>;

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

/** Messages for known concurrency, status, or general errors shown above the offer section. */
export function offerKnownGeneralError(errors: OfferFormErrors): string | null {
    for (const key of OFFER_KNOWN_GENERAL_KEYS) {
        const message = firstMessage(errors[key]);

        if (message) {
            return message;
        }
    }

    return null;
}

/**
 * Errors not rendered beside a specific field and not in known general keys.
 */
export function offerUnrenderedErrors(errors: OfferFormErrors): string[] {
    const rendered = new Set<string>([
        ...OFFER_FIELD_ERROR_KEYS,
        ...OFFER_KNOWN_GENERAL_KEYS,
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

export function offerFieldError(
    errors: OfferFormErrors,
    key: (typeof OFFER_FIELD_ERROR_KEYS)[number],
): string | undefined {
    return firstMessage(errors[key]) ?? undefined;
}
