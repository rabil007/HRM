import { Link } from '@inertiajs/react';
import { CheckCircle2, Clock, Copy, Flame, PlayCircle } from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { RequirementIndexRow } from '@/types/recruitment';
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
    onOpen,
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
        onOpen,
        onHold,
        onResume,
        onExtend,
        onChangeHeadcount,
        onFill,
        onCancel,
        onReopen,
        onRepeat,
    };

    const primaryAction = (() => {
        if (row.next_action === 'open' && row.can_open) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onOpen(row)}
                    className="h-10 gap-1.5 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
                >
                    <PlayCircle className="h-3.5 w-3.5" aria-hidden="true" />
                    Open
                </Button>
            );
        }

        if (row.next_action === 'resume' && row.can_resume) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onResume(row)}
                    className="h-10 gap-1.5 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
                >
                    <PlayCircle className="h-3.5 w-3.5" aria-hidden="true" />
                    Resume
                </Button>
            );
        }

        if (row.next_action === 'fill' && row.can_fill) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onFill(row)}
                    className="h-10 gap-1.5 border-sky-500/40 text-xs text-sky-700 hover:bg-sky-500/10 dark:text-sky-400"
                >
                    <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
                    Mark filled
                </Button>
            );
        }

        if (row.next_action === 'extend' && row.can_extend) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onExtend(row)}
                    className="h-10 gap-1.5 border-amber-500/40 text-xs text-amber-700 hover:bg-amber-500/10 dark:text-amber-400"
                >
                    <Clock className="h-3.5 w-3.5" aria-hidden="true" />
                    Extend
                </Button>
            );
        }

        if (row.next_action === 'repeat' && row.can_repeat) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onRepeat(row)}
                    className="h-10 gap-1.5 border-primary/40 text-xs text-primary hover:bg-primary/10"
                >
                    <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                    Repeat
                </Button>
            );
        }

        return null;
    })();

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
        <div
            className={cn(
                'rounded-xl border bg-card p-4 shadow-xs',
                row.deadline_health === 'overdue' && 'border-rose-500/30',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    {row.priority === 'urgent' && (
                        <Flame
                            className="h-3.5 w-3.5 fill-rose-500 text-rose-700 dark:text-rose-400"
                            aria-label="Urgent priority"
                        />
                    )}
                    <Link
                        href={showUrl}
                        className="font-mono text-sm font-bold text-foreground hover:text-primary hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {row.requirement_number}
                    </Link>
                    {row.repeated_from_number && (
                        <span className="text-xs text-muted-foreground">
                            <Copy
                                className="inline h-2.5 w-2.5"
                                aria-hidden="true"
                            />{' '}
                            {row.repeated_from_number}
                        </span>
                    )}
                </div>
                <RequirementStatusBadge
                    status={row.status}
                    label={row.status_label}
                />
            </div>

            <div className="mt-3">
                <span
                    className="block truncate text-base font-semibold text-foreground"
                    title={row.client_name}
                >
                    {row.client_name}
                </span>
                {(row.project_title || row.location) && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {row.project_title || ''}
                        {row.location ? ` • ${row.location}` : ''}
                    </span>
                )}
            </div>

            {row.positions_summary.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {row.positions_summary.slice(0, 2).map((position) => (
                        <span
                            key={position.id}
                            className="inline-flex max-w-full items-center truncate rounded border border-border/60 bg-muted/40 px-1.5 py-0.5 text-xs font-medium text-foreground/80"
                            title={`${position.position_title} — ${position.required_headcount} headcount`}
                        >
                            {position.position_title}
                            <span className="ml-1 font-semibold text-muted-foreground">
                                ×{position.required_headcount}
                            </span>
                        </span>
                    ))}
                    {row.positions_summary.length > 2 && (
                        <span className="inline-flex items-center rounded border border-border/40 bg-muted/20 px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                            +{row.positions_summary.length - 2} more
                        </span>
                    )}
                </div>
            )}

            <div className="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-muted/40 p-3">
                <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">
                        Staffing target
                    </p>
                    <p className="text-sm font-semibold">
                        <span className="text-xl tabular-nums">
                            {row.total_headcount}
                        </span>{' '}
                        {row.total_headcount === 1 ? 'person' : 'people'}
                    </p>
                </div>
                <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Required by</p>
                    <p className="text-sm font-medium">
                        {row.required_by_date_formatted || 'No deadline'}
                    </p>
                    <RequirementDeadlineBadge
                        health={row.deadline_health}
                        label={row.days_label}
                    />
                </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                <div className="flex min-w-0 items-center gap-2">
                    {recruiterInitials && (
                        <span
                            className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                            aria-hidden="true"
                        >
                            {recruiterInitials}
                        </span>
                    )}
                    <div className="min-w-0">
                        <p className="text-xs text-muted-foreground">
                            Recruiter
                        </p>
                        <p
                            className="max-w-[180px] truncate text-xs font-medium"
                            title={row.assigned_recruiter_name ?? undefined}
                        >
                            {row.assigned_recruiter_name || 'Unassigned'}
                        </p>
                    </div>
                </div>

                <div
                    className="flex items-center gap-1"
                    onClick={(e) => e.stopPropagation()}
                >
                    {primaryAction}
                    <RequirementActionMenu
                        row={row}
                        {...handlers}
                        triggerClassName="h-10 w-10 p-0 text-muted-foreground hover:text-foreground"
                    />
                </div>
            </div>
        </div>
    );
}
