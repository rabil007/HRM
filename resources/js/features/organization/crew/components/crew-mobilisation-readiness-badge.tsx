import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    hasConfiguredMobilisationChecks,
    mobilisationReadinessPresentationLabel,
    mobilisationReadinessTone,
} from '@/features/organization/crew/lib/mobilisation-readiness';
import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

const STATUS_STYLES: Record<string, string> = {
    ready: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    attention:
        'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    not_ready: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
    not_assessed:
        'border-slate-400/40 bg-slate-500/10 text-slate-700 dark:text-slate-200',
};

export function CrewMobilisationReadinessBadge({
    readiness,
    compact = false,
}: {
    readiness: CrewMobilisationReadiness;
    compact?: boolean;
}): ReactElement {
    const label = mobilisationReadinessPresentationLabel(readiness);
    const tone = mobilisationReadinessTone(readiness);
    const summary = [
        `Mobilisation readiness: ${label}`,
        hasConfiguredMobilisationChecks(readiness)
            ? `${readiness.checks_clear} of ${readiness.checks_total} checks clear`
            : 'No required document checks are configured for this employee.',
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
                    STATUS_STYLES[tone] ?? STATUS_STYLES.not_assessed,
                )}
            >
                <span className="truncate">{label}</span>
            </Badge>
            {!compact && hasConfiguredMobilisationChecks(readiness) ? (
                <p className="text-[11px] text-muted-foreground">
                    {readiness.checks_clear}/{readiness.checks_total} clear
                </p>
            ) : null}
        </div>
    );
}
