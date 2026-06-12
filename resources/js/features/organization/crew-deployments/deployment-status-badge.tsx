import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { DelayedHoverHint } from '@/features/organization/crew-deployments/delayed-hover-hint';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, string> = {
    on_vessel: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
    join_standby: 'bg-amber-500/10 text-amber-600 border-amber-500/20',
    leave_standby: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
    arrived: 'bg-sky-500/10 text-sky-600 border-sky-500/20',
    travel: 'bg-violet-500/10 text-violet-600 border-violet-500/20',
    disembarked: 'bg-zinc-500/10 text-muted-foreground border-border/60',
    unknown: 'bg-red-500/10 text-red-600 border-red-500/20',
};

export function DeploymentStatusBadge({
    status,
    label,
    hint,
    stopRowNavigation = false,
}: {
    status: string;
    label: string;
    hint?: string | null;
    stopRowNavigation?: boolean;
}): ReactElement {
    const badge = (
        <Badge
            variant="outline"
            className={cn(
                'font-medium',
                STATUS_STYLES[status] ?? STATUS_STYLES.unknown,
                hint && 'underline decoration-dotted decoration-current/40 underline-offset-[3px]',
            )}
        >
            {label}
        </Badge>
    );

    if (!hint) {
        return badge;
    }

    return (
        <DelayedHoverHint hint={hint} stopRowNavigation={stopRowNavigation}>
            {badge}
        </DelayedHoverHint>
    );
}
