import { Link, router } from '@inertiajs/react';
import { Flame } from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { cn } from '@/lib/utils';
import type { RequirementIndexRow } from '@/types/recruitment';
import { RequirementIndexQuickAction } from '../lib/requirement-index-quick-action';
import { RequirementActionMenu } from './requirement-action-menu';
import type { RequirementActionHandlers } from './requirement-action-menu';
import {
    RequirementDeadlineBadge,
    RequirementStatusBadge,
} from './requirement-status-badge';

type Props = RequirementActionHandlers & {
    row: RequirementIndexRow;
};

export function RequirementMobileCard({
    row,
    onEdit,
    onSubmit,
    onApprove,
    onReturn,
    onResubmit,
    onHold,
    onResume,
    onExtend,
    onChangeHeadcount,
    onFill,
    onCancel,
    onReopen,
    onRepeat,
}: Props) {
    const showUrl = RequirementController.show.url(row.id);
    const handlers = {
        onEdit,
        onSubmit,
        onApprove,
        onReturn,
        onResubmit,
        onHold,
        onResume,
        onExtend,
        onChangeHeadcount,
        onFill,
        onCancel,
        onReopen,
        onRepeat,
    };

    const recruiterInitials = (() => {
        const name = row.assigned_recruiter_name;

        if (!name) {
            return null;
        }

        const parts = name.trim().split(/\s+/);

        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }

        return parts[0].slice(0, 2).toUpperCase();
    })();

    return (
        <article
            className={cn(
                'rounded-xl border border-border/70 bg-card p-4 shadow-xs',
                row.deadline_health === 'overdue' && 'border-rose-500/30',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={showUrl}
                            className="font-mono text-xs font-semibold text-primary hover:underline"
                        >
                            {row.requirement_number}
                        </Link>
                        {row.priority === 'urgent' ? (
                            <span className="inline-flex items-center gap-1 rounded-md border border-rose-500/30 bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700 dark:text-rose-400">
                                <Flame
                                    className="size-2.5 fill-current"
                                    aria-hidden="true"
                                />
                                Urgent
                            </span>
                        ) : null}
                    </div>
                    <Link
                        href={showUrl}
                        className="block truncate text-sm font-semibold hover:underline"
                    >
                        {row.client_name}
                    </Link>
                    {(row.project_title || row.location) && (
                        <p className="truncate text-xs text-muted-foreground">
                            {[row.project_title, row.location]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}
                </div>
                <RequirementStatusBadge
                    status={row.status}
                    label={row.status_label}
                />
            </div>

            <div className="mt-3 grid grid-cols-2 gap-3 text-xs">
                <div>
                    <p className="text-muted-foreground">Headcount</p>
                    <p className="mt-0.5 font-semibold tabular-nums">
                        {row.total_headcount}{' '}
                        <span className="font-normal text-muted-foreground">
                            · {row.positions_count} roles
                        </span>
                    </p>
                </div>
                <div>
                    <p className="text-muted-foreground">Required by</p>
                    <p className="mt-0.5 font-semibold">
                        {row.required_by_date_formatted || 'No deadline'}
                    </p>
                    <RequirementDeadlineBadge
                        health={row.deadline_health}
                        label={row.days_label}
                        className="mt-1"
                    />
                </div>
            </div>

            <div className="mt-3 flex items-center justify-between gap-2 border-t border-border/50 pt-3">
                <div className="flex min-w-0 items-center gap-2">
                    {recruiterInitials ? (
                        <>
                            <div
                                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary"
                                aria-hidden="true"
                            >
                                {recruiterInitials}
                            </div>
                            <span className="truncate text-xs font-medium">
                                {row.assigned_recruiter_name}
                            </span>
                        </>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            Unassigned
                        </span>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                    <RequirementIndexQuickAction
                        row={row}
                        handlers={{
                            onSubmit,
                            onApprove,
                            onResubmit,
                            onResume,
                            onFill,
                            onExtend,
                            onReviewDeadlineExtension: () => {
                                router.visit(
                                    `${RequirementController.show.url(row.id)}#deadline-extension-request`,
                                );
                            },
                            onReviewHeadcountRevision: () => {
                                router.visit(
                                    `${RequirementController.show.url(row.id)}#headcount-revision-request`,
                                );
                            },
                            onRepeat,
                        }}
                    />
                    <RequirementActionMenu row={row} {...handlers} />
                </div>
            </div>
        </article>
    );
}
