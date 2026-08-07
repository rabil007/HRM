import type { PlanningProjectionPeriod } from '../types';

export type ProjectionBandMode = 'create' | 'inspect';

function formatIsoDisplay(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());

    if (!match) {
        return value.trim();
    }

    const [, year, month, day] = match;

    return `${day}-${month}-${year}`;
}

export function projectionBandMode(
    period: PlanningProjectionPeriod,
    canCreate: boolean,
    hasGapHandler: boolean,
): ProjectionBandMode {
    if (period.gap > 0 && canCreate && hasGapHandler) {
        return 'create';
    }

    return 'inspect';
}

export function periodTitle(
    period: PlanningProjectionPeriod,
    requiredCount: number,
): string {
    if (period.gap > 0) {
        return [
            'Projected gap',
            `Required: ${requiredCount}`,
            `Projected: ${period.projected_count}`,
            `Short: ${period.gap}`,
            `${formatIsoDisplay(period.from)} → ${formatIsoDisplay(period.to)}`,
        ].join('\n');
    }

    return [
        'Projected overlap',
        `Required: ${requiredCount}`,
        `Projected: ${period.projected_count}`,
        `Excess: ${period.excess}`,
        `${formatIsoDisplay(period.from)} → ${formatIsoDisplay(period.to)}`,
    ].join('\n');
}

export function bandAriaLabel(
    period: PlanningProjectionPeriod,
    requiredCount: number,
    mode: ProjectionBandMode,
): string {
    const detail = periodTitle(period, requiredCount).replaceAll('\n', '. ');

    if (mode === 'create') {
        return `Plan crew for projected gap starting ${period.from}. ${detail}`;
    }

    return detail;
}
