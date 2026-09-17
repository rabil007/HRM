import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { readinessStatusLabel } from '@/features/organization/crew/lib/assignment-readiness';
import type { EmployeeOperationalStatus } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

const STATUS_BADGE_STYLES: Record<string, string> = {
    on_vessel:
        'border-amber-500/50 bg-amber-500/15 text-amber-950 dark:text-amber-100',
    join_standby:
        'border-sky-500/40 bg-sky-500/10 text-sky-950 dark:text-sky-100',
    demob_standby:
        'border-indigo-500/40 bg-indigo-500/10 text-indigo-950 dark:text-indigo-100',
    in_home:
        'border-emerald-500/35 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100',
    home_redeploy:
        'border-emerald-500/35 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100',
    training:
        'border-blue-500/30 bg-blue-500/10 text-blue-950 dark:text-blue-100',
    ready_to_join:
        'border-primary/30 bg-primary/10 text-primary-foreground',
    pre_mobilisation:
        'border-blue-500/30 bg-blue-500/10 text-blue-950 dark:text-blue-100',
    travel_in:
        'border-blue-500/30 bg-blue-500/10 text-blue-950 dark:text-blue-100',
    movement_update_required:
        'border-rose-500/40 bg-rose-500/10 text-rose-950 dark:text-rose-100',
};

export function CrewEmployeeStatusBadge({
    status,
    className,
}: {
    status: EmployeeOperationalStatus | null | undefined;
    className?: string;
}): ReactElement | null {
    if (!status) {
        return null;
    }

    const label = readinessStatusLabel(status.status);

    return (
        <Badge
            variant="outline"
            className={cn(
                'font-semibold tracking-wide uppercase',
                STATUS_BADGE_STYLES[status.status] ??
                    'border-border/60 bg-muted/20 text-foreground',
                className,
            )}
        >
            {label}
        </Badge>
    );
}
