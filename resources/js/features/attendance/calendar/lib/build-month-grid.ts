import type { CalendarDayCell } from '../types';

function toIsoDate(year: number, month: number, day: number): string {
    const monthString = String(month + 1).padStart(2, '0');
    const dayString = String(day).padStart(2, '0');

    return `${year}-${monthString}-${dayString}`;
}

export function buildMonthGrid(year: number, month: number): CalendarDayCell[] {
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPreviousMonth = new Date(year, month, 0).getDate();
    const cells: CalendarDayCell[] = [];

    for (let index = firstDay - 1; index >= 0; index -= 1) {
        const day = daysInPreviousMonth - index;
        const previousMonth = month === 0 ? 11 : month - 1;
        const previousYear = month === 0 ? year - 1 : year;

        cells.push({
            date: toIsoDate(previousYear, previousMonth, day),
            day,
            inMonth: false,
        });
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        cells.push({
            date: toIsoDate(year, month, day),
            day,
            inMonth: true,
        });
    }

    let nextDay = 1;
    const nextMonth = month === 11 ? 0 : month + 1;
    const nextYear = month === 11 ? year + 1 : year;

    while (cells.length % 7 !== 0) {
        cells.push({
            date: toIsoDate(nextYear, nextMonth, nextDay),
            day: nextDay,
            inMonth: false,
        });
        nextDay += 1;
    }

    while (cells.length < 42) {
        cells.push({
            date: toIsoDate(nextYear, nextMonth, nextDay),
            day: nextDay,
            inMonth: false,
        });
        nextDay += 1;
    }

    return cells;
}

export function getIsoWeekNumber(dateString: string): number {
    const date = new Date(`${dateString}T00:00:00`);
    const utcDate = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNumber = utcDate.getUTCDay() || 7;
    utcDate.setUTCDate(utcDate.getUTCDate() + 4 - dayNumber);
    const yearStart = new Date(Date.UTC(utcDate.getUTCFullYear(), 0, 1));

    return Math.ceil((((utcDate.getTime() - yearStart.getTime()) / 86_400_000) + 1) / 7);
}
