import { AlertTriangle, Info } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type {
    CrewTimelineSummary,
    CrewTimelineWarningBreakdownItem,
} from './types';

function WarningBreakdownList({
    items,
    tone,
}: {
    items: CrewTimelineWarningBreakdownItem[];
    tone: 'blocking' | 'info';
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-1.5">
            {items.map((item) => (
                <Badge
                    key={item.code}
                    variant="outline"
                    className={cn(
                        'rounded-md font-medium',
                        tone === 'blocking'
                            ? 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-200'
                            : 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-200',
                    )}
                >
                    {item.label}
                    <span className="ml-1 tabular-nums opacity-70">
                        ×{item.count}
                    </span>
                </Badge>
            ))}
        </div>
    );
}

export function CrewTimelineWarningPanel({
    summary,
    isStale,
    breakdown,
}: {
    summary: CrewTimelineSummary;
    isStale: boolean;
    breakdown: CrewTimelineWarningBreakdownItem[];
}) {
    if (
        !isStale &&
        summary.blocking_warning_count === 0 &&
        summary.informational_warning_count === 0
    ) {
        return null;
    }

    const blockingItems = breakdown.filter((item) => item.is_blocking);
    const infoItems = breakdown.filter((item) => !item.is_blocking);

    return (
        <div className="space-y-3">
            {isStale ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Timeline changed</AlertTitle>
                    <AlertDescription>
                        The Crew Operations timeline changed after this
                        preparation was created. Prepare a new version before
                        continuing.
                    </AlertDescription>
                </Alert>
            ) : null}
            {summary.blocking_warning_count > 0 ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>
                        {summary.blocking_warning_count} blocking warning
                        {summary.blocking_warning_count === 1 ? '' : 's'}
                    </AlertTitle>
                    <AlertDescription>
                        Blocking warnings prevent submission and approval.
                        Correct Crew Operations data, then prepare a new
                        version. Open a flagged employee’s details to see the
                        affected lines.
                        <WarningBreakdownList
                            items={blockingItems}
                            tone="blocking"
                        />
                    </AlertDescription>
                </Alert>
            ) : null}
            {summary.informational_warning_count > 0 ? (
                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertTitle>
                        {summary.informational_warning_count} informational
                        warning
                        {summary.informational_warning_count === 1 ? '' : 's'}
                    </AlertTitle>
                    <AlertDescription>
                        Informational warnings do not block submission or
                        approval.
                        <WarningBreakdownList items={infoItems} tone="info" />
                    </AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}
