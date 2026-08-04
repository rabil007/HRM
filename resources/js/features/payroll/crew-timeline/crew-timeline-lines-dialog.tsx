import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    CircleDot,
    Clock3,
    Info,
    Ship,
    UserRound,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    formatCrewTimelineDate,
    formatCrewTimelineDateRange,
    formatCrewTimelineDays,
    summarizeCrewTimelineLines,
} from '@/features/payroll/lib/crew-timeline-lines';
import { cn } from '@/lib/utils';
import type { CrewTimelineEmployeeSummary, CrewTimelineLine } from './types';

function payCategoryClass(payCategory: string | null): string {
    switch (payCategory) {
        case 'onsite':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
        case 'sign_on_standby':
        case 'sign_off_standby':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
        case 'excluded':
            return 'border-border/70 bg-muted/50 text-muted-foreground';
        default:
            return 'border-border/70 bg-muted/30 text-foreground';
    }
}

function TimelineActualDates({ line }: { line: CrewTimelineLine }) {
    const hasActualDates =
        line.source_actual_start !== null || line.source_actual_end !== null;

    return (
        <div className="rounded-lg border border-border/50 bg-muted/20 p-3">
            <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Clock3 className="size-3.5" aria-hidden />
                Recorded activity
            </div>
            {hasActualDates ? (
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <dt className="text-muted-foreground">Start</dt>
                    <dd className="font-medium tabular-nums">
                        {formatCrewTimelineDate(line.source_actual_start)}
                    </dd>
                    <dt className="text-muted-foreground">End</dt>
                    <dd className="font-medium tabular-nums">
                        {formatCrewTimelineDate(line.source_actual_end)}
                    </dd>
                </dl>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No actual dates recorded
                </p>
            )}
        </div>
    );
}

function TimelineReviewMessage({ line }: { line: CrewTimelineLine }) {
    if (!line.warning && !line.remarks) {
        return null;
    }

    const isBlocking = line.warning?.is_blocking === true;

    return (
        <div
            className={cn(
                'mt-3 flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm',
                isBlocking
                    ? 'border-red-500/30 bg-red-500/[0.07] text-red-800 dark:text-red-200'
                    : line.warning
                      ? 'border-amber-500/30 bg-amber-500/[0.07] text-amber-800 dark:text-amber-200'
                      : 'border-border/60 bg-muted/20 text-muted-foreground',
            )}
        >
            {line.warning ? (
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
            ) : (
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            )}
            <div className="min-w-0 space-y-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold">
                        {line.warning?.label ?? 'Additional note'}
                    </p>
                    {line.warning ? (
                        <span className="rounded border border-current/20 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase opacity-75">
                            {isBlocking ? 'Blocks payroll' : 'Informational'}
                        </span>
                    ) : null}
                </div>
                {line.remarks ? (
                    <p className="leading-relaxed opacity-85">{line.remarks}</p>
                ) : null}
            </div>
        </div>
    );
}

export function CrewTimelineLinesDialog({
    employee,
    open,
    onOpenChange,
}: {
    employee: CrewTimelineEmployeeSummary | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!employee) {
        return null;
    }

    const summary = summarizeCrewTimelineLines(employee.lines);
    const informationalWarningCount =
        summary.warningCount - summary.blockingWarningCount;
    const identityDetails = [
        employee.employee_number
            ? { label: 'Employee', value: employee.employee_number }
            : null,
        employee.rank ? { label: 'Rank', value: employee.rank } : null,
        employee.assignment_number
            ? { label: 'Assignment', value: employee.assignment_number }
            : null,
        employee.vessel ? { label: 'Vessel', value: employee.vessel } : null,
    ].filter((detail): detail is { label: string; value: string } =>
        Boolean(detail),
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[92vh] w-[calc(100vw-1rem)] max-w-[calc(100vw-1rem)] flex-col gap-0 overflow-hidden glass-card p-0 sm:w-[94vw] sm:max-w-5xl">
                <DialogHeader className="shrink-0 border-b border-border/60 px-5 py-5 pr-12 text-left sm:px-6 sm:pr-14">
                    <div className="flex items-start gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <UserRound className="size-5" aria-hidden />
                        </div>
                        <div className="min-w-0 space-y-1">
                            <DialogTitle className="text-lg leading-tight">
                                {employee.employee_name ?? 'Employee'}
                            </DialogTitle>
                            <DialogDescription>
                                Payroll timeline · Review planned dates, actual
                                activity, and pay treatment for every crew
                                phase.
                            </DialogDescription>
                        </div>
                    </div>

                    {identityDetails.length > 0 ? (
                        <dl className="mt-3 flex flex-wrap gap-2">
                            {identityDetails.map((detail) => (
                                <div
                                    key={detail.label}
                                    className="flex min-w-0 items-center gap-1.5 rounded-md border border-border/60 bg-muted/30 px-2 py-1 text-xs"
                                >
                                    {detail.label === 'Vessel' ? (
                                        <Ship
                                            className="size-3 text-muted-foreground"
                                            aria-hidden
                                        />
                                    ) : null}
                                    <dt className="text-muted-foreground">
                                        {detail.label}
                                    </dt>
                                    <dd className="max-w-52 truncate font-medium text-foreground">
                                        {detail.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    ) : null}
                </DialogHeader>

                <div className="grid shrink-0 grid-cols-2 border-b border-border/60 bg-muted/15 sm:grid-cols-4">
                    <div className="border-r border-b border-border/50 px-4 py-3 sm:border-b-0 sm:px-5">
                        <p className="text-xs text-muted-foreground">
                            Payable days
                        </p>
                        <p className="mt-0.5 text-lg font-semibold tabular-nums">
                            {employee.total_payable_days.toFixed(2)}
                        </p>
                    </div>
                    <div className="border-b border-border/50 px-4 py-3 sm:border-r sm:border-b-0 sm:px-5">
                        <p className="text-xs text-muted-foreground">
                            Timeline entries
                        </p>
                        <p className="mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.lineCount}
                        </p>
                    </div>
                    <div className="border-r border-border/50 px-4 py-3 sm:px-5">
                        <p className="text-xs text-muted-foreground">
                            Excluded entries
                        </p>
                        <p className="mt-0.5 text-lg font-semibold tabular-nums">
                            {summary.excludedLineCount}
                        </p>
                    </div>
                    <div className="px-4 py-3 sm:px-5">
                        <p className="text-xs text-muted-foreground">
                            Needs review
                        </p>
                        <div className="mt-0.5 flex items-center gap-2">
                            <p
                                className={cn(
                                    'text-lg font-semibold tabular-nums',
                                    summary.blockingWarningCount > 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : summary.warningCount > 0
                                          ? 'text-amber-700 dark:text-amber-300'
                                          : 'text-emerald-700 dark:text-emerald-300',
                                )}
                            >
                                {summary.warningCount}
                            </p>
                            {summary.warningCount === 0 ? (
                                <CheckCircle2
                                    className="size-4 text-emerald-600 dark:text-emerald-400"
                                    aria-label="No warnings"
                                />
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {summary.blockingWarningCount > 0
                                        ? `${summary.blockingWarningCount} blocking`
                                        : `${informationalWarningCount} informational`}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto bg-muted/10 px-4 py-5 sm:px-6">
                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 className="text-sm font-semibold">
                                Phase-by-phase timeline
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Shown in processing order from earliest to
                                latest.
                            </p>
                        </div>
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <CircleDot className="size-3.5" aria-hidden />
                            {summary.lineCount}{' '}
                            {summary.lineCount === 1 ? 'entry' : 'entries'}
                        </p>
                    </div>

                    <div>
                        {employee.lines.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border/70 px-5 py-10 text-center">
                                <CalendarDays
                                    className="mx-auto size-8 text-muted-foreground/50"
                                    aria-hidden
                                />
                                <p className="mt-3 text-sm font-medium">
                                    No timeline entries
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    No payroll timeline was generated for this
                                    employee.
                                </p>
                            </div>
                        ) : (
                            employee.lines.map((line, index) => (
                                <article
                                    key={line.id}
                                    className="relative grid grid-cols-[1.5rem_minmax(0,1fr)] gap-3 pb-4 last:pb-0"
                                >
                                    <div
                                        className="relative flex justify-center"
                                        aria-hidden
                                    >
                                        {index < employee.lines.length - 1 ? (
                                            <span className="absolute top-4 bottom-[-1rem] w-px bg-border" />
                                        ) : null}
                                        <span
                                            className={cn(
                                                'relative mt-2 size-3 rounded-full border-2 border-background ring-2 ring-border',
                                                line.warning?.is_blocking
                                                    ? 'bg-red-500'
                                                    : line.warning
                                                      ? 'bg-amber-500'
                                                      : line.pay_category ===
                                                          'excluded'
                                                        ? 'bg-muted-foreground/50'
                                                        : 'bg-primary',
                                            )}
                                        />
                                    </div>

                                    <div
                                        className={cn(
                                            'min-w-0 rounded-xl border bg-card/70 p-4 shadow-sm',
                                            line.warning?.is_blocking
                                                ? 'border-red-500/25'
                                                : line.warning
                                                  ? 'border-amber-500/25'
                                                  : 'border-border/70',
                                        )}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Entry {index + 1}
                                                </p>
                                                <h4 className="mt-0.5 font-semibold text-foreground">
                                                    {line.phase_label ??
                                                        'Unlabelled phase'}
                                                </h4>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'rounded-md',
                                                    payCategoryClass(
                                                        line.pay_category,
                                                    ),
                                                )}
                                            >
                                                {line.pay_category_label ??
                                                    'No pay category'}
                                            </Badge>
                                        </div>

                                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                                            <div className="rounded-lg border border-border/50 bg-muted/20 p-3">
                                                <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    <CalendarDays
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    Planned period
                                                </div>
                                                <p className="text-sm font-medium tabular-nums">
                                                    {formatCrewTimelineDateRange(
                                                        line.from_date,
                                                        line.to_date,
                                                    )}
                                                </p>
                                                <Badge
                                                    variant="outline"
                                                    className="mt-2 rounded-md border-border/60 bg-background/60 text-muted-foreground tabular-nums"
                                                >
                                                    {formatCrewTimelineDays(
                                                        line.days,
                                                    )}
                                                </Badge>
                                            </div>
                                            <TimelineActualDates line={line} />
                                        </div>

                                        <TimelineReviewMessage line={line} />
                                    </div>
                                </article>
                            ))
                        )}
                    </div>
                </div>

                <DialogFooter className="shrink-0 items-center justify-between border-t border-border/60 bg-card/80 px-5 py-3 sm:px-6">
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Info className="size-3.5 shrink-0" aria-hidden />
                        Actual dates come from Crew Operations and are read-only
                        here.
                    </p>
                    <DialogClose asChild>
                        <Button type="button" className="w-full sm:w-auto">
                            Done
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
