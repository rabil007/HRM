import { useMemo } from 'react';
import { buildLeaveDayMap } from '../lib/build-leave-day-map';
import type { CalendarLeave } from '../types';
import { MonthMiniCalendar } from './month-mini-calendar';

export function YearCalendarGrid({
    year,
    today,
    approvedLeaves,
    canCreate,
    isSelecting,
    isDateInRange,
    onBeginSelection,
    onExtendSelection,
}: {
    year: number;
    today: string;
    approvedLeaves: CalendarLeave[];
    canCreate: boolean;
    isSelecting: boolean;
    isDateInRange: (date: string) => boolean;
    onBeginSelection: (date: string) => void;
    onExtendSelection: (date: string) => void;
}) {
    const leaveDayMap = useMemo(
        () => buildLeaveDayMap(approvedLeaves, year),
        [approvedLeaves, year],
    );
    const months = useMemo(
        () => Array.from({ length: 12 }, (_, index) => index),
        [],
    );

    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            {months.map((month) => (
                <MonthMiniCalendar
                    key={month}
                    year={year}
                    month={month}
                    today={today}
                    leaveDayMap={leaveDayMap}
                    canCreate={canCreate}
                    isSelecting={isSelecting}
                    isDateInRange={isDateInRange}
                    onBeginSelection={onBeginSelection}
                    onExtendSelection={onExtendSelection}
                />
            ))}
        </div>
    );
}
