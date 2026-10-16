import { usePage } from '@inertiajs/react';
import {
    DEFAULT_DATE_FORMAT,
    formatDisplayDate,
    isIsoDateString,
    SUPPORTED_DATE_FORMATS,
    syncPlatformDateFormatCache,
} from './format-date-core.ts';
import type { PlatformDateFormat } from './format-date-core.ts';

export {
    DEFAULT_DATE_FORMAT,
    formatDisplayDate,
    isIsoDateString,
    SUPPORTED_DATE_FORMATS,
    syncPlatformDateFormatCache,
};
export type { PlatformDateFormat };

export function useDateFormat(overrideFormat?: string | null): string {
    const page = usePage();

    if (
        overrideFormat &&
        typeof overrideFormat === 'string' &&
        overrideFormat.trim() !== ''
    ) {
        const trimmed = overrideFormat.trim();
        syncPlatformDateFormatCache(trimmed);

        return trimmed;
    }

    const settings = page?.props?.settings as
        | {
              platform?: { default_date_format?: string | null } | null;
              date_format?: string | null;
          }
        | undefined;

    const platformFormat = settings?.platform?.default_date_format;
    const flatFormat = settings?.date_format;
    const resolved = platformFormat || flatFormat || DEFAULT_DATE_FORMAT;
    syncPlatformDateFormatCache(resolved);

    return resolved;
}

export function useFormatDate(
    overrideFormat?: string | null,
): (value: string | null | undefined) => string {
    const dateFormat = useDateFormat(overrideFormat);

    return (value: string | null | undefined) =>
        formatDisplayDate(value, dateFormat);
}

function format12HourClockFromParts(hours24: number, minutes: string): string {
    const period = hours24 >= 12 ? 'PM' : 'AM';
    let hours = hours24 % 12;

    if (hours === 0) {
        hours = 12;
    }

    return `${hours}:${minutes} ${period}`;
}

function format12HourClock(parsed: Date): string {
    return format12HourClockFromParts(
        parsed.getHours(),
        String(parsed.getMinutes()).padStart(2, '0'),
    );
}

export function formatDisplayDateTime(
    value: string | null | undefined,
    format?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    const isoDate = `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}-${String(parsed.getDate()).padStart(2, '0')}`;
    const hours = String(parsed.getHours()).padStart(2, '0');
    const minutes = String(parsed.getMinutes()).padStart(2, '0');

    return `${formatDisplayDate(isoDate, format)} ${hours}:${minutes}`;
}

export function formatDisplayDateTimeInTimezone(
    value: string | null | undefined,
    timeZone: string,
    format?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(parsed);

    const get = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    const isoDate = `${get('year')}-${get('month')}-${get('day')}`;

    return `${formatDisplayDate(isoDate, format)} ${get('hour')}:${get('minute')}`;
}

export function formatDisplayTime12h(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    return format12HourClock(parsed);
}

export function formatDisplayDateTime12h(
    value: string | null | undefined,
    format?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    const isoDate = `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}-${String(parsed.getDate()).padStart(2, '0')}`;

    return `${formatDisplayDate(isoDate, format)} ${format12HourClock(parsed)}`;
}

export function useFormatDateTime(
    overrideFormat?: string | null,
): (value: string | null | undefined) => string {
    const dateFormat = useDateFormat(overrideFormat);

    return (value: string | null | undefined) =>
        formatDisplayDateTime(value, dateFormat);
}

export function useFormatDateTime12h(
    overrideFormat?: string | null,
): (value: string | null | undefined) => string {
    const dateFormat = useDateFormat(overrideFormat);

    return (value: string | null | undefined) =>
        formatDisplayDateTime12h(value, dateFormat);
}

export function formatDisplayValue(
    value: unknown,
    format?: string | null,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'string' && isIsoDateString(value)) {
        return formatDisplayDate(value, format);
    }

    if (typeof value === 'string') {
        return value;
    }

    try {
        return JSON.stringify(value);
    } catch {
        return String(value);
    }
}

export function formatActivityFieldLabel(key: string): string {
    return key
        .replace(/_id$/i, '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (m) => m.toUpperCase());
}

export {
    formatCompanyDateFromTimestamp,
    formatCompanyTimezoneLabel,
    formatDisplayDateTime12hInTimezone,
    isCompanyTimeInFuture,
    nowInCompanyDate,
    nowInCompanyTime,
    safeCompanyTimezone,
    toCompanyDateLocal,
    toCompanyDateTimeLocal,
    useCompanyTimezone,
} from './company-timezone.ts';
