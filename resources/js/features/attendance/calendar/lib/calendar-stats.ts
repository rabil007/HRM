import type { CalendarLeave } from '../types';
import { buildLeaveDayMap } from './build-leave-day-map';

export function getCalendarStats(leaves: CalendarLeave[], year: number) {
    const leaveDayMap = buildLeaveDayMap(leaves, year);

    return {
        requestCount: leaves.length,
        leaveDays: leaveDayMap.size,
    };
}
