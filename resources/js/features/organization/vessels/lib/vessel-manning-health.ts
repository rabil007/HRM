import type { VesselManningHealthStatus } from '../types';

export function vesselManningHealthBadgeClass(
    status: VesselManningHealthStatus,
): string {
    switch (status) {
        case 'healthy':
            return 'border-emerald-500/20 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
        case 'at_risk':
            return 'border-amber-500/20 bg-amber-500/15 text-amber-800 dark:text-amber-400';
        case 'critical':
            return 'border-red-500/20 bg-red-500/15 text-red-700 dark:text-red-400';
        case 'not_configured':
            return 'border-border/80 bg-muted/60 text-muted-foreground';
    }
}

export function vesselManningHealthDot(
    status: VesselManningHealthStatus,
): string {
    switch (status) {
        case 'healthy':
            return '🟢';
        case 'at_risk':
            return '🟠';
        case 'critical':
            return '🔴';
        case 'not_configured':
            return '⚪';
    }
}
