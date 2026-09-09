import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    CheckCircle2,
    ExternalLink,
    Info,
    ShieldAlert,
    Ship,
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
    buildCrewTimelinePayrollBreakdown,
    formatCrewTimelineArrowRange,
    formatCrewTimelineDayCount,
    formatCrewTimelineDays,
    formatCrewTimelinePeriodLabel,
} from '@/features/payroll/lib/crew-timeline-lines';
import type {
    CrewTimelineAssignmentLinkDivider,
    CrewTimelineBreakdownWarning,
    CrewTimelineExcludedSegment,
    CrewTimelinePayrollCategoryBreakdown,
    CrewTimelinePayrollSegment,
} from '@/features/payroll/lib/crew-timeline-lines';
import { formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import type { CrewTimelineEmployeeSummary, CrewTimelinePeriod } from './types';

function categoryTone(
    key: CrewTimelinePayrollCategoryBreakdown['key'],
): string {
    switch (key) {
        case 'onsite':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200';
        case 'sign_off_standby':
            return 'border-indigo-500/30 bg-indigo-500/10 text-indigo-800 dark:text-indigo-200';
        case 'sign_on_standby':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-800 dark:text-sky-200';
    }
}

function PayableSummaryMetric({
    label,
    days,
    tone,
}: {
    label: string;
    days: number;
    tone?: 'onsite' | 'sign_on' | 'sign_off' | 'total';
}) {
    return (
        <div
            className={cn(
                'rounded-xl border px-3 py-3',
                tone === 'onsite' && 'border-emerald-500/25 bg-emerald-500/5',
                tone === 'sign_on' && 'border-sky-500/25 bg-sky-500/5',
                tone === 'sign_off' && 'border-indigo-500/25 bg-indigo-500/5',
                tone === 'total' && 'border-primary/25 bg-primary/5',
                !tone && 'border-border/60 bg-card/60',
            )}
        >
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-lg font-semibold tabular-nums">
                {formatCrewTimelineDayCount(days)}
            </p>
        </div>
    );
}

function TransferDivider({
    divider,
}: {
    divider: CrewTimelineAssignmentLinkDivider;
}) {
    return (
        <div className="rounded-xl border border-dashed border-primary/30 bg-primary/5 px-4 py-3">
            <p className="text-[11px] font-semibold tracking-wider text-primary uppercase">
                {divider.label}
            </p>
            <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
                <AssignmentIdentity
                    assignmentId={divider.fromAssignmentId}
                    assignmentNumber={divider.fromAssignmentNumber}
                    vessel={divider.fromVessel}
                />
                <ArrowDown
                    className="mx-auto size-4 text-muted-foreground sm:-rotate-90"
                    aria-hidden
                />
                <AssignmentIdentity
                    assignmentId={divider.toAssignmentId}
                    assignmentNumber={divider.toAssignmentNumber}
                    vessel={divider.toVessel}
                />
            </div>
        </div>
    );
}

function AssignmentIdentity({
    assignmentId,
    assignmentNumber,
    vessel,
}: {
    assignmentId: number | null;
    assignmentNumber: string | null;
    vessel: string | null;
}) {
    const label = assignmentNumber ?? 'Assignment';

    return (
        <div className="min-w-0 rounded-lg border border-border/60 bg-background/70 px-3 py-2">
            {assignmentId ? (
                <Link
                    href={showAssignment.url(assignmentId)}
                    className="inline-flex max-w-full items-center gap-1 text-sm font-semibold text-primary hover:underline"
                >
                    <span className="truncate">{label}</span>
                    <ExternalLink className="size-3 shrink-0" aria-hidden />
                </Link>
            ) : (
                <p className="truncate text-sm font-semibold">{label}</p>
            )}
            {vessel ? (
                <p className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Ship className="size-3.5 shrink-0" aria-hidden />
                    <span className="truncate">{vessel}</span>
                </p>
            ) : null}
        </div>
    );
}

function PayableSegmentCard({
    segment,
    employeeRank,
}: {
    segment: CrewTimelinePayrollSegment;
    employeeRank: string | null;
}) {
    const showRank =
        segment.rank !== null &&
        segment.rank.trim() !== '' &&
        segment.rank !== employeeRank;
    const hasAssignmentContext =
        segment.assignmentNumber !== null ||
        segment.vessel !== null ||
        showRank;
    const showClient = segment.payCategory === 'onsite' && segment.client;

    return (
        <article className="rounded-xl border border-border/70 bg-card/80 p-3.5 sm:p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h4 className="font-semibold text-foreground">
                    {segment.title}
                </h4>
                <Badge
                    variant="outline"
                    className={cn(
                        'rounded-md',
                        categoryTone(segment.payCategory),
                    )}
                >
                    {formatCrewTimelineDays(segment.days)}
                </Badge>
            </div>

            {hasAssignmentContext ? (
                <div className="mt-3 space-y-1 text-sm">
                    {segment.assignmentId && segment.assignmentNumber ? (
                        <p>
                            <span className="text-muted-foreground">
                                Assignment:{' '}
                            </span>
                            <Link
                                href={showAssignment.url(segment.assignmentId)}
                                className="font-medium text-primary hover:underline"
                            >
                                {segment.assignmentNumber}
                            </Link>
                        </p>
                    ) : segment.assignmentNumber ? (
                        <p>
                            <span className="text-muted-foreground">
                                Assignment:{' '}
                            </span>
                            <span className="font-medium">
                                {segment.assignmentNumber}
                            </span>
                        </p>
                    ) : null}
                    {segment.vessel ? (
                        <p className="flex items-center gap-1.5">
                            <Ship
                                className="size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-muted-foreground">
                                Vessel:{' '}
                            </span>
                            <span className="font-medium">
                                {segment.vessel}
                            </span>
                        </p>
                    ) : null}
                    {showRank ? (
                        <p>
                            <span className="text-muted-foreground">
                                Rank:{' '}
                            </span>
                            <span className="font-medium">{segment.rank}</span>
                        </p>
                    ) : null}
                    {showClient ? (
                        <p>
                            <span className="text-muted-foreground">
                                Client:{' '}
                            </span>
                            <span className="font-medium">
                                {segment.client}
                            </span>
                        </p>
                    ) : null}
                </div>
            ) : null}

            <dl className="mt-3 space-y-2 text-sm">
                <div>
                    <dt className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Actual movement
                    </dt>
                    <dd className="mt-0.5 font-medium tabular-nums">
                        {formatCrewTimelineArrowRange(
                            segment.actualStart,
                            segment.actualEnd,
                            'No actual movement recorded',
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Payroll counted
                    </dt>
                    <dd className="mt-0.5 font-medium tabular-nums">
                        {formatCrewTimelineArrowRange(
                            segment.payrollFrom,
                            segment.payrollTo,
                            'No payroll dates',
                        )}
                    </dd>
                </div>
            </dl>

            {segment.warnings.length > 0 ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    {segment.warnings.some((warning) => warning.is_blocking)
                        ? 'This period has a blocking warning.'
                        : 'This period has an informational warning.'}
                </p>
            ) : null}
        </article>
    );
}

function CategoryBreakdown({
    category,
    employeeRank,
}: {
    category: CrewTimelinePayrollCategoryBreakdown;
    employeeRank: string | null;
}) {
    return (
        <section className="space-y-3">
            <div className="flex items-end justify-between gap-3">
                <h3 className="text-sm font-semibold tracking-wide uppercase">
                    {category.label}
                </h3>
                <p className="text-sm font-semibold tabular-nums">
                    {formatCrewTimelineDayCount(category.days)}
                </p>
            </div>

            {category.segments.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No payable periods were allocated for this category.
                </p>
            ) : (
                <div className="space-y-3">
                    {category.segments.map((segment) => (
                        <div key={segment.id} className="space-y-3">
                            {segment.linkFromPrevious ? (
                                <TransferDivider
                                    divider={segment.linkFromPrevious}
                                />
                            ) : null}
                            <PayableSegmentCard
                                segment={segment}
                                employeeRank={employeeRank}
                            />
                        </div>
                    ))}
                    {category.segments.length > 1 ? (
                        <p className="text-right text-xs font-medium text-muted-foreground">
                            Total {category.label}:{' '}
                            {formatCrewTimelineDayCount(category.days)}
                        </p>
                    ) : null}
                </div>
            )}
        </section>
    );
}

function WarningCard({ warning }: { warning: CrewTimelineBreakdownWarning }) {
    const isBlocking = warning.isBlocking;

    return (
        <article
            className={cn(
                'rounded-xl border px-3.5 py-3 text-sm',
                isBlocking
                    ? 'border-red-500/30 bg-red-500/[0.07] text-red-900 dark:text-red-100'
                    : 'border-amber-500/30 bg-amber-500/[0.07] text-amber-900 dark:text-amber-100',
            )}
        >
            <div className="flex items-start gap-2.5">
                {isBlocking ? (
                    <ShieldAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                ) : (
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                )}
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold">{warning.label}</p>
                        <span className="rounded border border-current/20 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase">
                            {isBlocking ? 'Blocks payroll' : 'Informational'}
                        </span>
                    </div>
                    <p className="font-medium tabular-nums">
                        {formatCrewTimelineArrowRange(
                            warning.from,
                            warning.to,
                            'No affected period',
                        )}
                    </p>
                    {warning.remarks ? (
                        <p className="leading-relaxed">{warning.remarks}</p>
                    ) : null}
                    <p className="text-xs opacity-80">
                        {isBlocking
                            ? 'Blocks payroll'
                            : 'Does not block payroll'}
                    </p>
                </div>
            </div>
        </article>
    );
}

function ExcludedSegmentCard({
    segment,
}: {
    segment: CrewTimelineExcludedSegment;
}) {
    return (
        <article className="rounded-xl border border-border/60 bg-muted/20 px-3.5 py-3 text-sm text-muted-foreground">
            <p className="font-medium text-foreground/80">{segment.title}</p>
            <p className="mt-1 tabular-nums">
                {formatCrewTimelineArrowRange(segment.from, segment.to)}
            </p>
            <p className="mt-1 text-xs">Excluded from payroll</p>
        </article>
    );
}

export function CrewTimelineLinesDialog({
    employee,
    period,
    open,
    onOpenChange,
}: {
    employee: CrewTimelineEmployeeSummary | null;
    period: Pick<CrewTimelinePeriod, 'name' | 'start_date'>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!employee) {
        return null;
    }

    const breakdown = buildCrewTimelinePayrollBreakdown(employee);
    const periodLabel = formatCrewTimelinePeriodLabel(period);
    const identity = [
        employee.employee_number
            ? `Employee ${employee.employee_number}`
            : null,
        employee.rank,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[92vh] w-[calc(100vw-1rem)] max-w-[calc(100vw-1rem)] flex-col gap-0 overflow-hidden glass-card p-0 sm:w-[94vw] sm:max-w-3xl">
                <DialogHeader className="shrink-0 border-b border-border/60 px-5 py-5 pr-12 text-left sm:px-6 sm:pr-14">
                    <DialogTitle className="text-lg leading-tight">
                        {employee.employee_name ?? 'Employee'}
                    </DialogTitle>
                    <DialogDescription>
                        {identity ||
                            'Payroll breakdown of operational days for this employee.'}
                    </DialogDescription>
                    <div className="pt-1">
                        <p className="text-sm font-semibold text-foreground">
                            Payroll Breakdown
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {periodLabel}
                        </p>
                    </div>
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto bg-muted/10 px-4 py-5 sm:px-6">
                    {employee.is_skipped ? (
                        <div className="mb-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                            <p className="text-xs font-semibold tracking-wide text-amber-900 uppercase dark:text-amber-200">
                                Crew Operations timeline skipped
                            </p>
                            <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Detected Crew Operations payable days
                                    </dt>
                                    <dd className="mt-0.5 font-semibold text-foreground tabular-nums">
                                        {formatCrewTimelineDayCount(
                                            breakdown.detectedPayableDays,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Applied from Crew Operations
                                    </dt>
                                    <dd className="mt-0.5 font-semibold text-foreground tabular-nums">
                                        {formatCrewTimelineDayCount(
                                            breakdown.appliedFromCrewOperationsDays,
                                        )}
                                    </dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <dt className="text-xs text-muted-foreground">
                                        Reason
                                    </dt>
                                    <dd className="mt-0.5 font-medium text-foreground">
                                        {employee.skip_reason ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Skipped by
                                    </dt>
                                    <dd className="mt-0.5 font-medium text-foreground">
                                        {employee.skipped_by?.name ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Skipped at
                                    </dt>
                                    <dd className="mt-0.5 font-medium text-foreground">
                                        {employee.skipped_at
                                            ? formatDisplayDateTime(
                                                  employee.skipped_at,
                                              )
                                            : '—'}
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                                Original Crew Operations data is preserved
                                below, but these operational days will not be
                                applied from this preparation.
                            </p>
                        </div>
                    ) : null}

                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <PayableSummaryMetric
                            label="Sign-On Standby"
                            days={employee.sign_on_standby_days}
                            tone="sign_on"
                        />
                        <PayableSummaryMetric
                            label="Onsite"
                            days={employee.onsite_days}
                            tone="onsite"
                        />
                        <PayableSummaryMetric
                            label="Sign-Off Standby"
                            days={employee.sign_off_standby_days}
                            tone="sign_off"
                        />
                        <PayableSummaryMetric
                            label={
                                employee.is_skipped
                                    ? 'Detected total'
                                    : 'Total Payable'
                            }
                            days={employee.total_payable_days}
                            tone="total"
                        />
                    </div>
                    {employee.is_skipped ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            These category totals are detected Crew Operations
                            days. They are not applied from this preparation.
                        </p>
                    ) : null}

                    <div className="mt-6 space-y-6">
                        <div>
                            <h2 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Payable breakdown
                            </h2>
                            {breakdown.categories.length === 0 ? (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    No payable Crew Operations days in this
                                    preparation.
                                </p>
                            ) : (
                                <div className="mt-3 space-y-6">
                                    {breakdown.categories.map((category) => (
                                        <CategoryBreakdown
                                            key={category.key}
                                            category={category}
                                            employeeRank={employee.rank}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        <div>
                            <h2 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Warnings
                            </h2>
                            {breakdown.warnings.length === 0 ? (
                                <div className="mt-3 flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
                                    <CheckCircle2
                                        className="size-4"
                                        aria-hidden
                                    />
                                    No warnings on this payroll breakdown.
                                </div>
                            ) : (
                                <div className="mt-3 space-y-2">
                                    {breakdown.warnings.map((warning) => (
                                        <WarningCard
                                            key={warning.id}
                                            warning={warning}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        {breakdown.excluded.length > 0 ? (
                            <div>
                                <h2 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Non-payable / excluded
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    These operational periods are not included
                                    in Total Payable.
                                </p>
                                <div className="mt-3 space-y-2">
                                    {breakdown.excluded.map((segment) => (
                                        <ExcludedSegmentCard
                                            key={segment.id}
                                            segment={segment}
                                        />
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>

                <DialogFooter className="shrink-0 items-center justify-between border-t border-border/60 bg-card/80 px-5 py-3 sm:px-6">
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Info className="size-3.5 shrink-0" aria-hidden />
                        Payroll counted dates are the days this preparation
                        uses. Actual movement is read-only Crew Operations data.
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
