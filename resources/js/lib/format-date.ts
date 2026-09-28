import { usePage } from '@inertiajs/react';

const ISO_DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})/;

export const SUPPORTED_DATE_FORMATS = [
    'Y-m-d',
    'd/m/Y',
    'm/d/Y',
    'd-m-Y',
    'M d, Y',
] as const;

export type PlatformDateFormat = (typeof SUPPORTED_DATE_FORMATS)[number];

export const DEFAULT_DATE_FORMAT: PlatformDateFormat = 'd-m-Y';

const SHORT_MONTH_NAMES: Record<string, string> = {
    '01': 'Jan',
    '02': 'Feb',
    '03': 'Mar',
    '04': 'Apr',
    '05': 'May',
    '06': 'Jun',
    '07': 'Jul',
    '08': 'Aug',
    '09': 'Sep',
    '10': 'Oct',
    '11': 'Nov',
    '12': 'Dec',
};

export function isIsoDateString(value: string): boolean {
    return ISO_DATE_PREFIX.test(value.trim());
}

export function formatDisplayDate(
    value: string | null | undefined,
    format?: string | null,
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return '—';
    }

    const match = ISO_DATE_PREFIX.exec(trimmed);

    if (!match) {
        return trimmed;
    }

    const [, year, month, day] = match;

    switch (format) {
        case 'Y-m-d':
            return `${year}-${month}-${day}`;
        case 'd/m/Y':
            return `${day}/${month}/${year}`;
        case 'm/d/Y':
            return `${month}/${day}/${year}`;
        case 'M d, Y': {
            const monthName = SHORT_MONTH_NAMES[month] ?? month;

            return `${monthName} ${day}, ${year}`;
        }
        case 'd-m-Y':
        default:
            return `${day}-${month}-${year}`;
    }
}

export function useDateFormat(overrideFormat?: string | null): string {
    const page = usePage();

    if (
        overrideFormat &&
        typeof overrideFormat === 'string' &&
        overrideFormat.trim() !== ''
    ) {
        return overrideFormat.trim();
    }

    const settings = page?.props?.settings as
        | {
              platform?: { default_date_format?: string | null } | null;
              date_format?: string | null;
          }
        | undefined;

    const platformFormat = settings?.platform?.default_date_format;
    const flatFormat = settings?.date_format;

    return platformFormat || flatFormat || DEFAULT_DATE_FORMAT;
}

export function useFormatDate(
    overrideFormat?: string | null,
): (value: string | null | undefined) => string {
    const dateFormat = useDateFormat(overrideFormat);

    return (value: string | null | undefined) =>
        formatDisplayDate(value, dateFormat);
}

export function formatDisplayDateTime(
    value: string | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    const day = String(parsed.getDate()).padStart(2, '0');
    const month = String(parsed.getMonth() + 1).padStart(2, '0');
    const year = parsed.getFullYear();
    const hours = String(parsed.getHours()).padStart(2, '0');
    const minutes = String(parsed.getMinutes()).padStart(2, '0');

    return `${day}-${month}-${year} ${hours}:${minutes}`;
}

export function formatDisplayDateTimeInTimezone(
    value: string | null | undefined,
    timeZone: string,
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

    return `${get('day')}-${get('month')}-${get('year')} ${get('hour')}:${get('minute')}`;
}

function format12HourClock(parsed: Date): string {
    const minutes = String(parsed.getMinutes()).padStart(2, '0');
    let hours = parsed.getHours();
    const period = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;

    if (hours === 0) {
        hours = 12;
    }

    return `${hours}:${minutes} ${period}`;
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
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const parsed = new Date(value.trim());

    if (Number.isNaN(parsed.getTime())) {
        return value.trim();
    }

    const day = String(parsed.getDate()).padStart(2, '0');
    const month = String(parsed.getMonth() + 1).padStart(2, '0');
    const year = parsed.getFullYear();

    return `${day}-${month}-${year} ${format12HourClock(parsed)}`;
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
