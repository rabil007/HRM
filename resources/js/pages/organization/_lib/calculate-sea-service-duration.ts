export type SeaServiceDurationResult = {
    months: number;
    days: number;
};

export function calculateSeaServiceDuration(
    startDate: string,
    endDate: string,
): SeaServiceDurationResult | null {
    if (!startDate || !endDate) {
        return null;
    }

    const start = parseIsoDate(startDate);
    const end = parseIsoDate(endDate);

    if (start === null || end === null || end < start) {
        return null;
    }

    let years = end.getFullYear() - start.getFullYear();
    let months = end.getMonth() - start.getMonth();
    let days = end.getDate() - start.getDate();

    if (days < 0) {
        months -= 1;
        const previousMonthLastDay = new Date(
            end.getFullYear(),
            end.getMonth(),
            0,
        ).getDate();
        days += previousMonthLastDay;
    }

    if (months < 0) {
        years -= 1;
        months += 12;
    }

    const inclusiveDays =
        Math.floor(
            (end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24),
        ) + 1;

    return {
        months: years * 12 + months,
        days: inclusiveDays,
    };
}

function parseIsoDate(value: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

    if (!match) {
        return null;
    }

    const year = Number.parseInt(match[1], 10);
    const month = Number.parseInt(match[2], 10) - 1;
    const day = Number.parseInt(match[3], 10);
    const date = new Date(year, month, day);

    if (
        date.getFullYear() !== year
        || date.getMonth() !== month
        || date.getDate() !== day
    ) {
        return null;
    }

    return date;
}
