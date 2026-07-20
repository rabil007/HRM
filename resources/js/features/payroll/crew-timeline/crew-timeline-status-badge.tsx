import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { CrewTimelinePreparationStatus } from './types';

const STATUS_STYLES: Record<CrewTimelinePreparationStatus, string> = {
    draft: 'border-border/60 bg-muted/30 text-muted-foreground',
    submitted: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200',
    returned:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200',
    approved:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
    applied:
        'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-200',
    superseded:
        'border-border/60 bg-muted/20 text-muted-foreground line-through',
};

export function CrewTimelineStatusBadge({
    status,
    label,
    className,
}: {
    status: CrewTimelinePreparationStatus | string;
    label: string;
    className?: string;
}) {
    const style =
        STATUS_STYLES[status as CrewTimelinePreparationStatus] ??
        STATUS_STYLES.draft;

    return (
        <Badge variant="outline" className={cn(style, className)}>
            {label}
        </Badge>
    );
}
