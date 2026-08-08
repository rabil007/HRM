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
            'Manning shortfall',
            `Required crew: ${requiredCount}`,
            `Available: ${period.projected_count}`,
            `Short: ${period.gap}`,
            `${formatIsoDisplay(period.from)} → ${formatIsoDisplay(period.to)}`,
        ].join('\n');
    }

    return [
        'Relief overlap',
        `Required crew: ${requiredCount}`,
        `Available: ${period.projected_count}`,
        `Extra: ${period.excess}`,
        `${formatIsoDisplay(period.from)} → ${formatIsoDisplay(period.to)}`,
    ].join('\n');
}

export function projectionExceptionLabel(status: string): string | null {
    switch (status) {
        case 'current_gap':
            return 'Manning Shortfall';
        case 'future_gap':
            return 'Future Shortfall';
        case 'overlap':
            return 'Relief Overlap';
        default:
            return null;
    }
}

export function bandAriaLabel(
    period: PlanningProjectionPeriod,
    requiredCount: number,
    mode: ProjectionBandMode,
): string {
    const detail = periodTitle(period, requiredCount).replaceAll('\n', '. ');

    if (mode === 'create') {
        return `Plan crew for manning shortfall starting ${period.from}. ${detail}`;
    }

    return detail;
}
