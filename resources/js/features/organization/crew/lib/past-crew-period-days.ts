/**
 * Inclusive calendar-day count for Past Crew Data movement periods.
 * Dates remain authoritative — operators never enter day totals manually.
 */
export function inclusivePeriodDays(
    from?: string | null,
    to?: string | null,
): string {
    if (!from || !to) {
        return '';
    }

    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);

    if (
        Number.isNaN(start.getTime()) ||
        Number.isNaN(end.getTime()) ||
        end < start
    ) {
        return '';
    }

    const days =
        Math.round((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)) +
        1;

    return String(days);
}
