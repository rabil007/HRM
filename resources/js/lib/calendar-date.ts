const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Pure calendar-day arithmetic on `YYYY-MM-DD` strings.
 * Never uses `toISOString()` — that shifts the day under non-UTC browser timezones.
 */
export function addIsoCalendarDays(
    isoDate: string | null | undefined,
    days: number,
): string | null {
    if (isoDate === null || isoDate === undefined) {
        return null;
    }

    const trimmed = isoDate.trim();
    const match = ISO_DATE.exec(trimmed);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const utc = new Date(Date.UTC(year, month - 1, day));

    if (
        Number.isNaN(utc.getTime()) ||
        utc.getUTCFullYear() !== year ||
        utc.getUTCMonth() !== month - 1 ||
        utc.getUTCDate() !== day
    ) {
        return null;
    }

    utc.setUTCDate(utc.getUTCDate() + days);

    const nextYear = utc.getUTCFullYear();
    const nextMonth = String(utc.getUTCMonth() + 1).padStart(2, '0');
    const nextDay = String(utc.getUTCDate()).padStart(2, '0');

    return `${nextYear}-${nextMonth}-${nextDay}`;
}

export function previousIsoCalendarDay(
    isoDate: string | null | undefined,
): string | null {
    return addIsoCalendarDays(isoDate, -1);
}
