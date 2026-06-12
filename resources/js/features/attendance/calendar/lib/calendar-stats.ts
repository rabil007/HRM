import { buildLeaveDayMap } from './build-leave-day-map';
import type { CalendarLeave } from '../types';

export function getCalendarStats(leaves: CalendarLeave[], year: number) {
    const leaveDayMap = buildLeaveDayMap(leaves, year);

    return {
        requestCount: leaves.length,
        leaveDays: leaveDayMap.size,
    };
}
