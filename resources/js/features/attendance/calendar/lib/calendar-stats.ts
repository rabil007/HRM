import { buildLeaveDayMap } from './build-leave-day-map';
import type { CalendarLeave } from '../types';

export function getCalendarStats(leaves: CalendarLeave[], year: number) {
    const leaveDayMap = buildLeaveDayMap(leaves, year);
    const typeIds = new Set<number>();

    for (const leave of leaves) {
        if (leave.leave_type?.id) {
            typeIds.add(leave.leave_type.id);
        }
    }

    return {
        requestCount: leaves.length,
        leaveDays: leaveDayMap.size,
        typeCount: typeIds.size,
    };
}
