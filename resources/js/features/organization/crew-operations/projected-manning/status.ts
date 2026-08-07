import type { ProjectedManningStatus } from './types';

export type ProjectedManningBadgeVariant =
    | 'default'
    | 'secondary'
    | 'destructive'
    | 'outline'
    | 'success'
    | 'warning'
    | 'info';

export function projectedManningStatusVariant(
    status: ProjectedManningStatus,
): ProjectedManningBadgeVariant {
    switch (status) {
        case 'current_gap':
            return 'destructive';
        case 'future_gap':
            return 'warning';
        case 'overlap':
            return 'warning';
        case 'covered_by_incoming':
            return 'secondary';
        case 'covered':
        default:
            return 'success';
    }
}

export function hasProjectedManningGap(
    status: ProjectedManningStatus,
): boolean {
    return status === 'current_gap' || status === 'future_gap';
}
