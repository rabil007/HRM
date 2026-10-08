import type { CalendarLeave } from '../types.ts';
import { buildLeaveDayMap } from './build-leave-day-map.ts';

export function getCalendarStats(leaves: CalendarLeave[], year: number) {
    const approvedLeaves = leaves.filter(
        (leave) => leave.status === 'approved',
    );
    const leaveDayMap = buildLeaveDayMap(approvedLeaves, year);

    return {
        requestCount: approvedLeaves.length,
        leaveDays: leaveDayMap.size,
    };
}
