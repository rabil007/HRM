/**
 * Inclusive day count between two ISO dates (YYYY-MM-DD).
 * Returns an empty string when either date is missing or the range is invalid.
 */
export function calculateInclusiveDays(from: string, to: string): string {
    const start = parseLocalDate(from);
    const end = parseLocalDate(to);

    if (start === null || end === null) {
        return '';
    }

    if (end < start) {
        return '';
    }

    const diffMs = end.getTime() - start.getTime();
    const days = Math.floor(diffMs / 86_400_000) + 1;

    return formatDayCount(days);
}

function parseLocalDate(value: string): Date | null {
    const trimmed = value.trim();
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]) - 1;
    const day = Number(match[3]);
    const date = new Date(year, month, day);

    if (
        date.getFullYear() !== year ||
        date.getMonth() !== month ||
        date.getDate() !== day
    ) {
        return null;
    }

    return date;
}

function formatDayCount(days: number): string {
    return days.toFixed(2);
}
