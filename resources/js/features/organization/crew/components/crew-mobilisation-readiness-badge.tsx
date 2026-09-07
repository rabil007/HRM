import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, string> = {
    ready: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    attention:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    not_ready: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
};

export function CrewMobilisationReadinessBadge({
    readiness,
    compact = false,
}: {
    readiness: CrewMobilisationReadiness;
    compact?: boolean;
}): ReactElement {
    const summary = [
        `Mobilisation readiness: ${readiness.status_label}`,
        readiness.checks_total > 0
            ? `${readiness.checks_clear} of ${readiness.checks_total} checks clear`
            : 'No required document checks',
    ].join('. ');

    return (
        <div
            className="flex min-w-0 flex-col gap-0.5"
            role="group"
            aria-label={summary}
        >
            <Badge
                variant="outline"
                className={cn(
                    'w-fit max-w-full font-medium',
                    STATUS_STYLES[readiness.status] ?? STATUS_STYLES.attention,
                )}
            >
                <span className="truncate">{readiness.status_label}</span>
            </Badge>
            {!compact && readiness.checks_total > 0 ? (
                <p className="text-[11px] text-muted-foreground">
                    {readiness.checks_clear}/{readiness.checks_total} clear
                </p>
            ) : null}
        </div>
    );
}
