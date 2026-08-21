import type { CalendarLeave } from '../types';
import { buildLeaveDayMap } from './build-leave-day-map';

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
