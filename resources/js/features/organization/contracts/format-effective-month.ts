const SHORT_MONTH_NAMES = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;

/**
 * Month-only label for salary revision effective_from (stored as month-start ISO).
 * Intentionally not tied to platform full-date format.
 */
export function formatEffectiveMonth(value: string | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return '—';
    }

    const match = /^(\d{4})-(\d{2})/.exec(trimmed);

    if (!match) {
        return trimmed;
    }

    const year = match[1];
    const monthIndex = Number.parseInt(match[2], 10) - 1;
    const monthName = SHORT_MONTH_NAMES[monthIndex];

    if (!monthName) {
        return trimmed;
    }

    return `${monthName} ${year}`;
}
