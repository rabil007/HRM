export type SeaServiceTotalsRow = {
    total_months: number;
    total_days: number;
};

export function formatSeaServiceTotalsYmd<T extends SeaServiceTotalsRow>(
    rows: T[],
    filter?: (r: T) => boolean,
): string {
    const list = filter ? rows.filter(filter) : rows;
    let months = 0;
    let days = 0;

    for (const r of list) {
        months += Number(r.total_months) || 0;
        days += Number(r.total_days) || 0;
    }

    months += Math.floor(days / 30);
    days %= 30;
    const years = Math.floor(months / 12);
    months %= 12;

    return `${years}Y/${months}M/${days}D`;
}
