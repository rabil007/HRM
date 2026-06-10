import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, string> = {
    on_vessel: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
    standby: 'bg-amber-500/10 text-amber-600 border-amber-500/20',
    awaiting_join: 'bg-sky-500/10 text-sky-600 border-sky-500/20',
    travel: 'bg-violet-500/10 text-violet-600 border-violet-500/20',
    disembarked: 'bg-zinc-500/10 text-muted-foreground border-border/60',
    unknown: 'bg-red-500/10 text-red-600 border-red-500/20',
};

export function DeploymentStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}): ReactElement {
    return (
        <Badge
            variant="outline"
            className={cn('font-medium', STATUS_STYLES[status] ?? STATUS_STYLES.unknown)}
        >
            {label}
        </Badge>
    );
}
