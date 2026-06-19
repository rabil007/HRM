export function toUtcDateMs(localDate: Date): number {
    return Date.UTC(localDate.getFullYear(), localDate.getMonth(), localDate.getDate());
}

export function formatUtcIsoDate(utcMs: number): string {
    const d = new Date(utcMs);
    const year = d.getUTCFullYear();
    const month = String(d.getUTCMonth() + 1).padStart(2, '0');
    const day = String(d.getUTCDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
}

export function parseIsoToUtcMs(iso: string): number {
    const [y, m, d] = iso.split('-').map(Number);
    
    return Date.UTC(y, m - 1, d);
}

/** Returns CSS left%/width% positioning for a bar within [rangeFrom, rangeTo]. */
export function barPositionStyle(
    start: string,
    end: string,
    rangeFrom: Date,
    rangeTo: Date,
): { left: string; width: string } | { display: 'none' } {
    const rangeFromMs = toUtcDateMs(rangeFrom);
    const rangeToMs = toUtcDateMs(rangeTo) + 86400000;
    const totalMs = rangeToMs - rangeFromMs;

    if (totalMs <= 0) {
        return { display: 'none' };
    }

    const startMs = parseIsoToUtcMs(start);
    const endMs = parseIsoToUtcMs(end) + 86400000;

    const left = Math.max(0, ((startMs - rangeFromMs) / totalMs) * 100);
    const right = Math.min(100, ((endMs - rangeFromMs) / totalMs) * 100);
    const width = right - left;

    if (width <= 0) {
        return { display: 'none' };
    }

    return { left: `${left}%`, width: `${width}%` };
}

/** Formats a Date as YYYY-MM-DD in local time (avoids UTC shift from toISOString). */
export function formatIsoDateLocal(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/** Converts a fractional X position [0, 1] within the timeline to an ISO date string. */
export function dateFromPointerRatio(ratio: number, rangeFrom: Date, rangeTo: Date): string {
    const rangeFromMs = toUtcDateMs(rangeFrom);
    const rangeToMs = toUtcDateMs(rangeTo) + 86400000;
    const totalMs = rangeToMs - rangeFromMs;
    const clamped = Math.max(0, Math.min(1, ratio));

    const dayOffset = Math.floor((clamped * totalMs) / 86400000);
    const maxDays = Math.round(totalMs / 86400000) - 1;
    const finalDayOffset = Math.min(dayOffset, maxDays);

    return formatUtcIsoDate(rangeFromMs + finalDayOffset * 86400000);
}

/** Returns the whole-day delta (may be fractional for sub-day, floor it for display). */
export function daysBetween(start: string, end: string): number {
    const startMs = parseIsoToUtcMs(start);
    const endMs = parseIsoToUtcMs(end);

    return Math.round((endMs - startMs) / 86400000);
}

/** Inclusive assignment length from planned join through planned leave. */
export function assignmentDurationDays(start: string, end: string): number {
    return daysBetween(start, end) + 1;
}

/** Shifts both start and end by dayDelta days, preserving duration. */
export function shiftDateRange(
    start: string,
    end: string,
    dayDelta: number,
): { start: string; end: string } {
    const startMs = parseIsoToUtcMs(start) + dayDelta * 86400000;
    const endMs = parseIsoToUtcMs(end) + dayDelta * 86400000;

    return {
        start: formatUtcIsoDate(startMs),
        end: formatUtcIsoDate(endMs),
    };
}

/** Converts a pixel delta within the timeline to a fractional day count. */
export function pxToDays(pxDelta: number, containerWidth: number, rangeFrom: Date, rangeTo: Date): number {
    const rangeFromMs = toUtcDateMs(rangeFrom);
    const rangeToMs = toUtcDateMs(rangeTo) + 86400000;
    const totalDays = Math.round((rangeToMs - rangeFromMs) / 86400000);

    return (pxDelta / containerWidth) * totalDays;
}
