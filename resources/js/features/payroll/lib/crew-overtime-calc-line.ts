function formatNum(n: number): string {
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

export function buildOvertimeCalcLine(
    hours: number,
    overtimeHourlyRate: number,
    hourRate?: number,
): string | null {
    if (hours <= 0) {
        return null;
    }

    if (hourRate !== undefined && hourRate > 0) {
        return `${formatNum(hours)} × (${formatNum(hourRate)} × 1.25)`;
    }

    if (overtimeHourlyRate > 0) {
        return `${formatNum(hours)} × ${formatNum(overtimeHourlyRate)}`;
    }

    return null;
}
