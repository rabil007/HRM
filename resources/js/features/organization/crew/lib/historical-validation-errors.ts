const FORM_ALIASES: Record<string, string> = {
    // Legacy event keys → simplified period fields (compat for older payloads)
    training_start_at: 'sign_on_standby_from',
    training_end_at: 'sign_on_standby_to',
    mobilisation_start_at: 'sign_on_standby_from',
    mobilisation_at: 'sign_on_standby_from',
    join_standby_at: 'sign_on_standby_from',
    demob_standby_at: 'sign_off_standby_from',
    post_signoff_standby_at: 'sign_off_standby_from',
    joined_vessel_at: 'onsite_from',
    disembarked_at: 'onsite_to',
    home_redeploy_at: 'home_available_from',
};

const ALERT_KEYS = [
    'overlap',
    'sea_service',
    'assignment',
    'dates',
    'workbook',
    'file',
] as const;

function firstMessage(value: string | string[] | undefined): string | null {
    if (value == null) {
        return null;
    }

    if (Array.isArray(value)) {
        return value[0] ?? null;
    }

    return value;
}

/**
 * Map backend historical validation keys onto Manual Entry form field names
 * and collect a visible alert message for domain-level failures.
 */
export function mapHistoricalValidationErrors(
    errors: Record<string, string | string[]>,
): {
    fieldErrors: Record<string, string>;
    alertMessage: string | null;
} {
    const fieldErrors: Record<string, string> = {};
    const alertParts: string[] = [];

    for (const [key, raw] of Object.entries(errors)) {
        const message = firstMessage(raw);

        if (!message) {
            continue;
        }

        fieldErrors[key] = message;

        const alias = FORM_ALIASES[key];

        if (alias && !fieldErrors[alias]) {
            fieldErrors[alias] = message;
        }

        if ((ALERT_KEYS as readonly string[]).includes(key)) {
            alertParts.push(message);
        }
    }

    if (alertParts.length > 0) {
        return {
            fieldErrors,
            alertMessage: [...new Set(alertParts)].join(' '),
        };
    }

    return {
        fieldErrors,
        alertMessage: null,
    };
}
