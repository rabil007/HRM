import { usePage } from '@inertiajs/react';

/**
 * Validates an IANA timezone identifier and falls back to a safe alternative or 'UTC'.
 */
export function safeCompanyTimezone(
    timeZone?: string | null,
    fallback = 'UTC',
): string {
    if (timeZone && typeof timeZone === 'string' && timeZone.trim() !== '') {
        const trimmed = timeZone.trim();

        try {
            new Intl.DateTimeFormat(undefined, { timeZone: trimmed });

            return trimmed;
        } catch {
            // Invalid IANA identifier, fall through to fallback
        }
    }

    if (fallback && typeof fallback === 'string' && fallback.trim() !== '') {
        const trimmedFallback = fallback.trim();

        try {
            new Intl.DateTimeFormat(undefined, { timeZone: trimmedFallback });

            return trimmedFallback;
        } catch {
            // Fallback is also invalid
        }
    }

    return 'UTC';
}

/**
 * Returns a human-friendly label for a timezone, including its current standard/DST offset.
 * Example: "Gulf Standard Time (UTC+4)" or "British Summer Time (UTC+1)".
 */
export function formatCompanyTimezoneLabel(
    timeZone?: string | null,
    referenceDate?: Date,
): string {
    const safeTz = safeCompanyTimezone(timeZone);
    const date = referenceDate ?? new Date();

    try {
        const longName = new Intl.DateTimeFormat('en-US', {
            timeZone: safeTz,
            timeZoneName: 'long',
        })
            .formatToParts(date)
            .find((part) => part.type === 'timeZoneName')?.value;

        const offset = new Intl.DateTimeFormat('en-US', {
            timeZone: safeTz,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(date)
            .find((part) => part.type === 'timeZoneName')?.value;

        const normalizedOffset = (offset ?? '').replace('GMT', 'UTC');

        if (longName && normalizedOffset) {
            return `${longName} (${normalizedOffset})`;
        }

        return safeTz;
    } catch {
        return safeTz;
    }
}

/**
 * Formats a Date object into YYYY-MM-DDTHH:mm using the specified timezone.
 * Uses 24-hour cycle (00-23) to guarantee deterministic midnight formatting.
 */
function formatInTimezone(date: Date, timeZone: string): string {
    const safeTz = safeCompanyTimezone(timeZone);
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: safeTz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        hour12: false,
    }).formatToParts(date);

    const get = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/**
 * Converts a stored timestamp, ISO string, Date object, or wall-clock string into
 * a company-local `datetime-local` string formatted as `YYYY-MM-DDTHH:mm`.
 */
export function toCompanyDateTimeLocal(
    value: string | Date | null | undefined,
    timeZone?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const safeTz = safeCompanyTimezone(timeZone);

    if (value instanceof Date) {
        return Number.isNaN(value.getTime())
            ? ''
            : formatInTimezone(value, safeTz);
    }

    const trimmed = String(value).trim();

    if (!trimmed) {
        return '';
    }

    // Already a datetime-local format without timezone offset: YYYY-MM-DDTHH:mm
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(trimmed)) {
        return trimmed;
    }

    // Wall-clock display from backend snapshot: YYYY-MM-DD HH:mm
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(trimmed)) {
        return trimmed.replace(' ', 'T');
    }

    // Wall-clock display with seconds: YYYY-MM-DD HH:mm:ss -> truncate seconds
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(trimmed)) {
        // Backend SQL timestamps without timezone are stored in UTC:
        const parsedUtc = new Date(trimmed.replace(' ', 'T') + 'Z');

        if (!Number.isNaN(parsedUtc.getTime())) {
            return formatInTimezone(parsedUtc, safeTz);
        }
    }

    // ISO timestamp with timezone designator (Z or +/-offset)
    const parsed = new Date(trimmed);

    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    return formatInTimezone(parsed, safeTz);
}

/**
 * Converts a stored timestamp or date into the company's local calendar day `YYYY-MM-DD`.
 */
export function toCompanyDateLocal(
    value: string | Date | null | undefined,
    timeZone?: string | null,
): string {
    const dt = toCompanyDateTimeLocal(value, timeZone);

    return dt ? dt.slice(0, 10) : '';
}

/**
 * Returns current instant formatted as `YYYY-MM-DDTHH:mm` in the company's timezone.
 */
export function nowInCompanyTime(
    timeZone?: string | null,
    referenceDate?: Date,
): string {
    return toCompanyDateTimeLocal(
        referenceDate ?? new Date(),
        safeCompanyTimezone(timeZone),
    );
}

/**
 * Returns current instant formatted as `YYYY-MM-DD` in the company's timezone.
 */
export function nowInCompanyDate(
    timeZone?: string | null,
    referenceDate?: Date,
): string {
    return toCompanyDateLocal(
        referenceDate ?? new Date(),
        safeCompanyTimezone(timeZone),
    );
}

/**
 * Formats a timestamp as `DD-MM-YYYY hh:mm A` in the specified company timezone.
 */
export function formatDisplayDateTime12hInTimezone(
    value: string | Date | null | undefined,
    timeZone?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const safeTz = safeCompanyTimezone(timeZone);

    // If it's already a wall-clock datetime-local string (YYYY-MM-DDTHH:mm or YYYY-MM-DDTHH:mm:ss)
    const str = typeof value === 'string' ? value.trim() : '';
    const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(str);

    if (match && !str.includes('Z') && !/[+-]\d{2}:\d{2}$/.test(str)) {
        const [, year, month, day, hourStr, minStr] = match;
        let hours = parseInt(hourStr, 10);
        const period = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;

        if (hours === 0) {
            hours = 12;
        }

        return `${day}-${month}-${year} ${hours}:${minStr} ${period}`;
    }

    const date = value instanceof Date ? value : new Date(str);

    if (Number.isNaN(date.getTime())) {
        return str || '—';
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: safeTz,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).formatToParts(date);

    const get = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${get('day')}-${get('month')}-${get('year')} ${get('hour')}:${get('minute')} ${get('dayPeriod').toUpperCase()}`;
}

/**
 * Checks whether a given datetime-local string (YYYY-MM-DDTHH:mm) represents a time
 * strictly in the future relative to the current company wall-clock time.
 */
export function isCompanyTimeInFuture(
    value: string | null | undefined,
    timeZone?: string | null,
    referenceDate?: Date,
): boolean {
    if (!value || typeof value !== 'string') {
        return false;
    }

    const trimmed = value.trim().slice(0, 16);

    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(trimmed)) {
        return false;
    }

    const currentCompanyTime = nowInCompanyTime(timeZone, referenceDate);

    return trimmed > currentCompanyTime;
}

/**
 * React hook to resolve the active company timezone from Inertia's shared page props,
 * with optional component-level override.
 */
export function useCompanyTimezone(overrideTimezone?: string | null): string {
    const page = usePage();

    if (
        overrideTimezone &&
        typeof overrideTimezone === 'string' &&
        overrideTimezone.trim() !== ''
    ) {
        return safeCompanyTimezone(overrideTimezone);
    }

    const settings = page?.props?.settings as
        | {
              company?: { timezone?: string | null } | null;
              timezone?: string | null;
              platform?: { fallback_timezone?: string | null } | null;
          }
        | undefined;

    const companyTz = settings?.company?.timezone;
    const flatTz = settings?.timezone;
    const fallbackTz = settings?.platform?.fallback_timezone;

    return safeCompanyTimezone(companyTz || flatTz || fallbackTz || 'UTC');
}
