const FORM_ALIASES: Record<string, string> = {
    training_start_at: 'training_started_at',
    training_end_at: 'training_ended_at',
    mobilisation_start_at: 'mobilisation_at',
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
