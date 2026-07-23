import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { CrewOperationalSource } from '../types';

const SOURCE_STYLES: Record<CrewOperationalSource, { className: string }> = {
    crew_operations: {
        className:
            'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-200',
    },
    import: {
        className:
            'border-violet-500/25 bg-violet-500/10 text-violet-700 dark:text-violet-200',
    },
    manual: {
        className:
            'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-200',
    },
    monthly_crew: {
        className:
            'border-teal-500/25 bg-teal-500/10 text-teal-700 dark:text-teal-200',
    },
    not_entered: {
        className:
            'border-muted-foreground/25 bg-muted/40 text-muted-foreground',
    },
};

export function CrewOperationalSourceBadge({
    source,
    label,
    className,
}: {
    source: CrewOperationalSource;
    label?: string;
    className?: string;
}) {
    const style = SOURCE_STYLES[source];

    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-md px-1.5 py-0 text-[10px] font-semibold tracking-wide',
                style.className,
                className,
            )}
        >
            {label ?? source}
        </Badge>
    );
}
