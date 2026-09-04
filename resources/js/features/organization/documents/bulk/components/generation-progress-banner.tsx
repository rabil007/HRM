import { Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    generationDisplayName,
    generationHasIssues,
    isGenerationRunActive,
} from '@/features/organization/documents/lib/generation-run-progress';
import { cn } from '@/lib/utils';
import { design as designTemplate } from '@/routes/organization/documents/templates';
import type { BulkGenerationRun } from '../types';

export function GenerationProgressBanner({
    latestRun,
    onShowGenerated,
    canViewTemplates = false,
}: {
    latestRun: BulkGenerationRun | null;
    onShowGenerated?: () => void;
    canViewTemplates?: boolean;
}) {
    const [dismissedRunId, setDismissedRunId] = useState<number | null>(null);

    const status = latestRun?.status;
    const isQueued = status === 'queued';
    const isRunning = status === 'running';
    const isActive = isGenerationRunActive(status);
    const isFailed = status === 'failed';
    const isCompleted = status === 'completed';
    const hasIssues = latestRun !== null && generationHasIssues(latestRun);
    const keepUntilDismissed = isFailed || hasIssues;
    const failureSummary = latestRun?.failure_summary ?? null;

    useEffect(() => {
        if (!latestRun || !isCompleted || keepUntilDismissed) {
            return;
        }

        const runId = latestRun.id;
        const timeout = window.setTimeout(() => {
            setDismissedRunId(runId);
        }, 12000);

        return () => window.clearTimeout(timeout);
    }, [isCompleted, keepUntilDismissed, latestRun]);

    if (!latestRun) {
        return null;
    }

    if (!isActive && !isFailed && !isCompleted) {
        return null;
    }

    if (!isActive && dismissedRunId === latestRun.id) {
        return null;
    }

    const name = generationDisplayName(latestRun);
    const processed = latestRun.processed_count;
    const percent = latestRun.progress_percent;
    const secondaryParts: string[] = [];

    if (latestRun.generated_count > 0) {
        secondaryParts.push(`${latestRun.generated_count} generated`);
    }

    if ((latestRun.processing_count ?? 0) > 0) {
        secondaryParts.push(
            `${latestRun.processing_count} currently processing`,
        );
    }

    if (latestRun.skipped_count > 0) {
        secondaryParts.push(`${latestRun.skipped_count} skipped`);
    }

    if (latestRun.failed_count > 0) {
        secondaryParts.push(`${latestRun.failed_count} failed`);
    }

    let title = '';
    let detail = '';

    if (isQueued) {
        title = `Preparing ${name}`;
        detail = `${latestRun.total_targeted} documents queued`;
    } else if (isRunning) {
        title = `Generating ${name}`;
        detail = `${processed} of ${latestRun.total_targeted} processed`;
    } else if (hasIssues) {
        title = 'Generation completed with issues';
        detail =
            failureSummary?.headline ??
            `${processed} of ${latestRun.total_targeted} processed`;
    } else if (isCompleted) {
        title = 'Generation completed';
        detail = `${processed} of ${latestRun.total_targeted} processed`;
    } else {
        title = 'Generation failed';
        detail =
            failureSummary?.headline ??
            'Documents could not be generated. Please try again or review the logs.';
    }

    const showEditTemplate =
        Boolean(failureSummary?.show_edit_template) &&
        canViewTemplates &&
        latestRun.template_id != null;

    return (
        <div
            className={cn(
                'mb-6 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                isActive &&
                    'border-amber-500/25 bg-amber-500/6 text-amber-700 dark:text-amber-400',
                isCompleted &&
                    !hasIssues &&
                    'border-emerald-500/25 bg-emerald-500/6 text-emerald-700 dark:text-emerald-400',
                hasIssues &&
                    'border-amber-500/25 bg-amber-500/6 text-amber-800 dark:text-amber-400',
                isFailed &&
                    'border-destructive/25 bg-destructive/6 text-destructive',
            )}
        >
            {isActive ? (
                <Spinner className="mt-0.5 h-4 w-4 shrink-0" />
            ) : (
                <span
                    className={cn(
                        'mt-1.5 flex h-2 w-2 shrink-0 rounded-full',
                        isCompleted && !hasIssues && 'bg-emerald-500',
                        hasIssues && 'bg-amber-500',
                        isFailed && 'bg-destructive',
                    )}
                />
            )}
            <div className="min-w-0 flex-1 space-y-2">
                <div className="font-medium">{title}</div>
                {isRunning ? (
                    <div
                        className="h-2 overflow-hidden rounded-full bg-foreground/10"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                        aria-label="Document generation progress"
                    >
                        <div
                            className="h-full rounded-full bg-amber-500"
                            style={{
                                width: `${Math.min(100, Math.max(0, percent))}%`,
                            }}
                        />
                    </div>
                ) : null}
                <div className="text-sm">
                    {isRunning ? (
                        <>
                            {detail}
                            <span className="ml-2 tabular-nums">
                                {percent}%
                            </span>
                        </>
                    ) : (
                        detail
                    )}
                </div>
                {failureSummary &&
                failureSummary.items.length > 0 &&
                (hasIssues || isFailed) ? (
                    <ul className="space-y-1 text-xs">
                        {failureSummary.items.map((item) => (
                            <li key={item.employee_id}>
                                <span className="font-medium">
                                    {item.employee_name}
                                </span>
                                <span className="opacity-80">
                                    {' '}
                                    — {item.message}
                                </span>
                            </li>
                        ))}
                        {failureSummary.additional_failure_count > 0 ? (
                            <li className="opacity-80">
                                +{failureSummary.additional_failure_count} more
                            </li>
                        ) : null}
                    </ul>
                ) : null}
                {secondaryParts.length > 0 && (isRunning || isCompleted) ? (
                    <div className="text-xs opacity-80">
                        {secondaryParts.join(' · ')}
                    </div>
                ) : null}
                <div className="flex flex-wrap items-center gap-2">
                    {isCompleted && onShowGenerated ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={onShowGenerated}
                        >
                            Show generated
                        </Button>
                    ) : null}
                    {showEditTemplate && latestRun.template_id != null ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            asChild
                        >
                            <Link
                                href={designTemplate.url(latestRun.template_id)}
                            >
                                Edit template
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>
            {!isActive ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="ml-auto h-6 w-6 shrink-0 rounded-full hover:bg-foreground/10"
                    onClick={() => setDismissedRunId(latestRun.id)}
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            ) : null}
        </div>
    );
}
