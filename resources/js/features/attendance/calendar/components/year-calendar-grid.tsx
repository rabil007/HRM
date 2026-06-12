import { useMemo } from 'react';
import { buildLeaveDayMap } from '../lib/build-leave-day-map';
import type { CalendarLeave } from '../types';
import { MonthMiniCalendar, MonthMiniCalendarProvider } from './month-mini-calendar';

export function YearCalendarGrid({
    year,
    today,
    approvedLeaves,
}: {
    year: number;
    today: string;
    approvedLeaves: CalendarLeave[];
}) {
    const leaveDayMap = useMemo(() => buildLeaveDayMap(approvedLeaves, year), [approvedLeaves, year]);
    const months = useMemo(() => Array.from({ length: 12 }, (_, index) => index), []);

    return (
        <MonthMiniCalendarProvider>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                {months.map((month) => (
                    <MonthMiniCalendar
                        key={month}
                        year={year}
                        month={month}
                        today={today}
                        leaveDayMap={leaveDayMap}
                    />
                ))}
            </div>
        </MonthMiniCalendarProvider>
    );
}
