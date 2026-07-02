import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { DelayedHoverHint } from '@/features/organization/crew-deployments/delayed-hover-hint';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, string> = {
    available:
        'border-cyan-500/20 bg-cyan-500/10 text-cyan-700 dark:border-cyan-500/30 dark:bg-cyan-500/15 dark:text-cyan-300',
    on_vessel:
        'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
    join_standby:
        'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
    leave_standby:
        'border-orange-500/20 bg-orange-500/10 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/15 dark:text-orange-300',
    arrived:
        'border-sky-500/20 bg-sky-500/10 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300',
    travel: 'border-violet-500/20 bg-violet-500/10 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/15 dark:text-violet-300',
    in_home:
        'border-teal-500/20 bg-teal-500/10 text-teal-700 dark:border-teal-500/30 dark:bg-teal-500/15 dark:text-teal-300',
    disembarked:
        'border-rose-500/20 bg-rose-500/10 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-300',
    unknown:
        'border-red-500/20 bg-red-500/10 text-red-700 dark:border-red-500/30 dark:bg-red-500/15 dark:text-red-300',
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
                hint &&
                    'underline decoration-current/40 decoration-dotted underline-offset-[3px]',
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
