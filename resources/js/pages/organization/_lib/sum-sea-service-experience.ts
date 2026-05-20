export type SeaServiceTotalsRow = {
    total_months: number;
    total_days: number;
    start_date?: string | null;
    end_date?: string | null;
};

function seaServiceRowPeriodDays(row: SeaServiceTotalsRow): number {
    if (row.start_date && row.end_date) {
        return Number(row.total_days) || 0;
    }

    return (
        (Number(row.total_months) || 0) * 30 + (Number(row.total_days) || 0)
    );
}

export function formatSeaServiceTotalsYmd<T extends SeaServiceTotalsRow>(
    rows: T[],
    filter?: (r: T) => boolean,
): string {
    const list = filter ? rows.filter(filter) : rows;
    let periodDays = 0;

    for (const r of list) {
        periodDays += seaServiceRowPeriodDays(r);
    }

    let months = Math.floor(periodDays / 30);
    const days = periodDays % 30;
    const years = Math.floor(months / 12);
    months %= 12;

    return `${years}Y/${months}M/${days}D`;
}
