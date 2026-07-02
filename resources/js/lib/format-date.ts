const ISO_DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})/;

export function isIsoDateString(value: string): boolean {
    return ISO_DATE_PREFIX.test(value.trim());
}

export function formatDisplayDate(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const trimmed = value.trim();
    const match = ISO_DATE_PREFIX.exec(trimmed);

    if (!match) {
        return trimmed;
    }

    const [, year, month, day] = match;

    return `${day}-${month}-${year}`;
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

export function formatDisplayValue(value: unknown): string {
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
        return formatDisplayDate(value);
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
