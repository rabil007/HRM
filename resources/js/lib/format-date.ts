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
