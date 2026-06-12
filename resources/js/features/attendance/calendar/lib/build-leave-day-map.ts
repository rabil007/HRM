import type { CalendarLeave } from '../types';

function toIsoDate(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function buildLeaveDayMap(leaves: CalendarLeave[], year: number): Map<string, CalendarLeave[]> {
    const map = new Map<string, CalendarLeave[]>();
    const yearStart = `${year}-01-01`;
    const yearEnd = `${year}-12-31`;

    for (const leave of leaves) {
        if (!leave.start_date || !leave.end_date) {
            continue;
        }

        const rangeStart = leave.start_date < yearStart ? yearStart : leave.start_date;
        const rangeEnd = leave.end_date > yearEnd ? yearEnd : leave.end_date;

        if (rangeStart > rangeEnd) {
            continue;
        }

        const cursor = new Date(`${rangeStart}T00:00:00`);
        const end = new Date(`${rangeEnd}T00:00:00`);

        while (cursor <= end) {
            const key = toIsoDate(cursor);
            const existing = map.get(key) ?? [];
            existing.push(leave);
            map.set(key, existing);
            cursor.setDate(cursor.getDate() + 1);
        }
    }

    return map;
}
