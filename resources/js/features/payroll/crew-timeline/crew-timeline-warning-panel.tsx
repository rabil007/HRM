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
    const visibleItems = items.filter((item) => {
        const count =
            tone === 'blocking'
                ? (item.unresolved_count ?? item.count)
                : (item.total_count ?? item.count);

        return count > 0;
    });

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-1.5">
            {visibleItems.map((item) => {
                const unresolved = item.unresolved_count ?? item.count;
                const skipped = item.skipped_count ?? 0;

                return (
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
                        {tone === 'blocking' ? (
                            skipped > 0 ? (
                                <>
                                    <span className="ml-1 font-semibold tabular-nums">
                                        {unresolved} unresolved
                                    </span>
                                    <span className="ml-1 text-[11px] font-normal opacity-75">
                                        ({skipped} skipped)
                                    </span>
                                </>
                            ) : (
                                <span className="ml-1 font-semibold tabular-nums">
                                    ×{unresolved}
                                </span>
                            )
                        ) : (
                            <span className="ml-1 tabular-nums opacity-70">
                                ×{item.count}
                            </span>
                        )}
                    </Badge>
                );
            })}
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
    const unresolvedBlockers =
        summary.unresolved_blocking_warning_count ??
        summary.blocking_warning_count;
    const skippedCount = summary.skipped_employees ?? 0;

    if (
        !isStale &&
        summary.blocking_warning_count === 0 &&
        summary.informational_warning_count === 0 &&
        skippedCount === 0
    ) {
        return null;
    }

    const blockingItems = breakdown.filter((item) => item.is_blocking);
    const infoItems = breakdown.filter((item) => !item.is_blocking);
    const skippedBlockingItems = breakdown.filter(
        (item) => item.is_blocking && (item.skipped_count ?? 0) > 0,
    );

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

            {unresolvedBlockers > 0 ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>
                        {unresolvedBlockers} unresolved blocking warning
                        {unresolvedBlockers === 1 ? '' : 's'}
                    </AlertTitle>
                    <AlertDescription>
                        Blocking warnings prevent submission and approval.
                        Correct Crew Operations data and prepare a new version,
                        or skip the affected employee&apos;s timeline data if
                        permitted.
                        <WarningBreakdownList
                            items={blockingItems}
                            tone="blocking"
                        />
                    </AlertDescription>
                </Alert>
            ) : null}

            {skippedCount > 0 ? (
                <Alert className="border-sky-500/30 bg-sky-500/5 text-sky-950 dark:bg-sky-500/10 dark:text-sky-100">
                    <Info className="h-4 w-4 text-sky-600 dark:text-sky-400" />
                    <AlertTitle>
                        {skippedCount} employee
                        {skippedCount === 1 ? '' : 's'} skipped
                    </AlertTitle>
                    <AlertDescription>
                        Their Crew Operations timeline data will not be applied
                        to timesheets. They may require Manual/Excel data or
                        payroll exclusion before generation.
                        {skippedBlockingItems.length > 0 ? (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {skippedBlockingItems.map((item) => (
                                    <Badge
                                        key={item.code}
                                        variant="outline"
                                        className="rounded-md border-sky-500/40 bg-sky-500/10 font-medium text-sky-800 dark:text-sky-200"
                                    >
                                        {item.label}
                                        <span className="ml-1 tabular-nums opacity-75">
                                            {item.skipped_count} skipped
                                        </span>
                                    </Badge>
                                ))}
                            </div>
                        ) : null}
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
