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

/**
 * Filled by useDateFormat() in React trees (e.g. AppLayout) so pure
 * formatDisplayDate(value) callers pick up the live platform setting.
 */
let platformDateFormatCache: string | null = null;

export function syncPlatformDateFormatCache(format: string | null | undefined): void {
    if (typeof format === 'string' && format.trim() !== '') {
        platformDateFormatCache = format.trim();
    }
}

export function resolveDisplayDateFormat(format?: string | null): string {
    if (typeof format === 'string' && format.trim() !== '') {
        return format.trim();
    }

    return platformDateFormatCache || DEFAULT_DATE_FORMAT;
}

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

/**
 * Pure date-only formatter. Pass platform format from useDateFormat()/useFormatDate().
 * When format is omitted, falls back to DEFAULT_DATE_FORMAT (d-m-Y).
 */
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
    const resolved = resolveDisplayDateFormat(format);

    switch (resolved) {
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

export function formatCalendarParts(
    year: string,
    month: string,
    day: string,
    format?: string | null,
): string {
    return formatDisplayDate(`${year}-${month}-${day}`, format);
}
