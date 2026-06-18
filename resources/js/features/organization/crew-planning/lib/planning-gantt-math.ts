/** Returns CSS left%/width% positioning for a bar within [rangeFrom, rangeTo]. */
export function barPositionStyle(
    start: string,
    end: string,
    rangeFrom: Date,
    rangeTo: Date,
): { left: string; width: string } | { display: 'none' } {
    const totalMs = rangeTo.getTime() - rangeFrom.getTime();

    if (totalMs <= 0) {
        return { display: 'none' };
    }

    const startMs = new Date(`${start}T00:00:00`).getTime();
    const endMs = new Date(`${end}T23:59:59`).getTime();

    const left = Math.max(0, ((startMs - rangeFrom.getTime()) / totalMs) * 100);
    const right = Math.min(100, ((endMs - rangeFrom.getTime()) / totalMs) * 100);
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
    const totalMs = rangeTo.getTime() - rangeFrom.getTime();
    const clamped = Math.max(0, Math.min(1, ratio));

    return formatIsoDateLocal(new Date(rangeFrom.getTime() + clamped * totalMs));
}

/** Returns the whole-day delta (may be fractional for sub-day, floor it for display). */
export function daysBetween(start: string, end: string): number {
    const startMs = new Date(`${start}T00:00:00`).getTime();
    const endMs = new Date(`${end}T00:00:00`).getTime();

    return Math.round((endMs - startMs) / 86_400_000);
}

/** Shifts both start and end by dayDelta days, preserving duration. */
export function shiftDateRange(
    start: string,
    end: string,
    dayDelta: number,
): { start: string; end: string } {
    const shiftMs = dayDelta * 86_400_000;
    const newStart = new Date(new Date(`${start}T00:00:00`).getTime() + shiftMs);
    const newEnd = new Date(new Date(`${end}T00:00:00`).getTime() + shiftMs);

    return {
        start: formatIsoDateLocal(newStart),
        end: formatIsoDateLocal(newEnd),
    };
}

/** Converts a pixel delta within the timeline to a fractional day count. */
export function pxToDays(pxDelta: number, containerWidth: number, rangeFrom: Date, rangeTo: Date): number {
    const totalMs = rangeTo.getTime() - rangeFrom.getTime();
    const totalDays = totalMs / 86_400_000;

    return (pxDelta / containerWidth) * totalDays;
}
