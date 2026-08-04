import {
    AlertTriangle,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Info,
    Ship,
    Shuffle,
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
    buildCrewTimelineAssignmentSections,
    formatAssignmentCountLabel,
    formatCrewTimelineDate,
    formatCrewTimelineDateRange,
    formatCrewTimelineDays,
    phaseOccurrenceTitle,
    summarizeCrewTimelineEmployee,
} from '@/features/payroll/lib/crew-timeline-lines';
import type {
    CrewTimelineAssignmentLinkDivider,
    CrewTimelineAssignmentSection,
} from '@/features/payroll/lib/crew-timeline-lines';
import { cn } from '@/lib/utils';
import type {
    CrewTimelineEmployeeSummary,
    CrewTimelinePhaseOccurrence,
    CrewTimelinePhaseWarning,
} from './types';

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

function SummaryMetric({
    label,
    value,
    tone,
}: {
    label: string;
    value: string | number;
    tone?: 'danger' | 'warning' | 'success' | 'default';
}) {
    return (
        <div className="border-border/50 px-3 py-3 sm:px-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'mt-0.5 text-lg font-semibold tabular-nums',
                    tone === 'danger' && 'text-red-700 dark:text-red-300',
                    tone === 'warning' && 'text-amber-700 dark:text-amber-300',
                    tone === 'success' &&
                        'text-emerald-700 dark:text-emerald-300',
                )}
            >
                {value}
            </p>
        </div>
    );
}

function AssignmentLinkDivider({
    divider,
}: {
    divider: CrewTimelineAssignmentLinkDivider;
}) {
    return (
        <div className="my-4 rounded-xl border border-dashed border-primary/30 bg-primary/5 px-4 py-3">
            <div className="flex items-center gap-2 text-sm font-semibold text-primary">
                <Shuffle className="size-4 shrink-0" aria-hidden />
                {divider.label}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium">
                    {divider.fromAssignmentNumber ?? 'Previous assignment'}
                </span>
                <ArrowRight
                    className="size-3.5 text-muted-foreground"
                    aria-hidden
                />
                <span className="font-medium">
                    {divider.toAssignmentNumber ?? 'New assignment'}
                </span>
            </div>
            {(divider.fromVessel || divider.toVessel) && (
                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Ship className="size-3.5" aria-hidden />
                    <span>{divider.fromVessel ?? '—'}</span>
                    <ArrowRight
                        className="size-3 text-muted-foreground"
                        aria-hidden
                    />
                    <span>{divider.toVessel ?? '—'}</span>
                </div>
            )}
        </div>
    );
}

function PhaseWarnings({ warnings }: { warnings: CrewTimelinePhaseWarning[] }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 space-y-2">
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Warnings
            </p>
            {warnings.map((warning) => {
                const isBlocking = warning.is_blocking;

                return (
                    <div
                        key={`${warning.line_id}-${warning.code}`}
                        className={cn(
                            'flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-sm',
                            isBlocking
                                ? 'border-red-500/30 bg-red-500/[0.07] text-red-800 dark:text-red-200'
                                : 'border-amber-500/30 bg-amber-500/[0.07] text-amber-800 dark:text-amber-200',
                        )}
                    >
                        <AlertTriangle
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden
                        />
                        <div className="min-w-0 space-y-0.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-semibold">{warning.label}</p>
                                <span className="rounded border border-current/20 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase opacity-75">
                                    {isBlocking
                                        ? 'Blocks payroll'
                                        : 'Informational'}
                                </span>
                            </div>
                            {warning.remarks ? (
                                <p className="leading-relaxed opacity-85">
                                    {warning.remarks}
                                </p>
                            ) : null}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function PhaseCard({
    phase,
    isLast,
}: {
    phase: CrewTimelinePhaseOccurrence;
    isLast: boolean;
}) {
    const hasBlocking = phase.warnings.some((warning) => warning.is_blocking);
    const hasWarning = phase.warnings.length > 0;
    const hasActualDates =
        phase.actual_start !== null || phase.actual_end !== null;
    const hasPlannedDates =
        phase.planned_start !== null || phase.planned_end !== null;
    const treatment = phase.primary_treatment;

    return (
        <article className="relative grid grid-cols-[1.5rem_minmax(0,1fr)] gap-3 pb-4 last:pb-0">
            <div className="relative flex justify-center" aria-hidden>
                {!isLast ? (
                    <span className="absolute top-4 bottom-[-1rem] w-px bg-border" />
                ) : null}
                <span
                    className={cn(
                        'relative mt-2 size-3 rounded-full border-2 border-background ring-2 ring-border',
                        hasBlocking
                            ? 'bg-red-500'
                            : hasWarning
                              ? 'bg-amber-500'
                              : treatment?.pay_category === 'excluded'
                                ? 'bg-muted-foreground/50'
                                : 'bg-primary',
                    )}
                />
            </div>

            <div
                className={cn(
                    'min-w-0 rounded-xl border bg-card/70 p-4 shadow-sm',
                    hasBlocking
                        ? 'border-red-500/25'
                        : hasWarning
                          ? 'border-amber-500/25'
                          : 'border-border/70',
                )}
            >
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <h4 className="font-semibold text-foreground">
                            {phaseOccurrenceTitle(phase)}
                        </h4>
                        {phase.status_label ? (
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Status: {phase.status_label}
                            </p>
                        ) : null}
                    </div>
                    {treatment ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                'rounded-md',
                                payCategoryClass(treatment.pay_category),
                            )}
                        >
                            {treatment.pay_category_label}
                        </Badge>
                    ) : null}
                </div>

                <div className="mt-3 grid gap-3 md:grid-cols-2">
                    {phase.has_planned_schedule || hasPlannedDates ? (
                        <div className="rounded-lg border border-border/50 bg-muted/20 p-3">
                            <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                <CalendarDays
                                    className="size-3.5"
                                    aria-hidden
                                />
                                Planned schedule
                            </div>
                            <p className="text-sm font-medium tabular-nums">
                                {formatCrewTimelineDateRange(
                                    phase.planned_start,
                                    phase.planned_end,
                                    'No planned dates',
                                )}
                            </p>
                            {phase.planned_date_origin_label ? (
                                <p className="mt-1.5 text-[11px] text-muted-foreground">
                                    {phase.planned_date_origin_label}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="rounded-lg border border-border/50 bg-muted/20 p-3">
                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            <Clock3 className="size-3.5" aria-hidden />
                            Actual activity
                        </div>
                        {hasActualDates ? (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                                <dt className="text-muted-foreground">Start</dt>
                                <dd className="font-medium tabular-nums">
                                    {formatCrewTimelineDate(phase.actual_start)}
                                </dd>
                                <dt className="text-muted-foreground">End</dt>
                                <dd className="font-medium tabular-nums">
                                    {formatCrewTimelineDate(phase.actual_end)}
                                </dd>
                            </dl>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No actual dates recorded
                            </p>
                        )}
                    </div>

                    {phase.has_payroll_period ||
                    phase.payroll_from ||
                    phase.payroll_to ? (
                        <div className="rounded-lg border border-border/50 bg-muted/20 p-3 md:col-span-2">
                            <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                <CalendarDays
                                    className="size-3.5"
                                    aria-hidden
                                />
                                {phase.payroll_period_label ??
                                    'Payroll allocation'}
                            </div>
                            <p className="text-sm font-medium tabular-nums">
                                {formatCrewTimelineDateRange(
                                    phase.payroll_from,
                                    phase.payroll_to,
                                    'No allocation dates',
                                )}
                            </p>
                            {phase.payroll_date_origin_label ? (
                                <p className="mt-1.5 text-[11px] text-muted-foreground">
                                    {phase.payroll_date_origin_label}
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <div className="mt-3 flex flex-wrap gap-2">
                    {treatment ? (
                        <Badge
                            variant="outline"
                            className="rounded-md border-border/60 bg-background/60"
                        >
                            Payroll treatment: {treatment.pay_category_label}
                        </Badge>
                    ) : null}
                    <Badge
                        variant="outline"
                        className="rounded-md border-border/60 bg-background/60 tabular-nums"
                    >
                        Payable days:{' '}
                        {formatCrewTimelineDays(phase.payable_days)}
                    </Badge>
                    {phase.excluded_treatment ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                'rounded-md',
                                payCategoryClass('excluded'),
                            )}
                        >
                            Also excluded:{' '}
                            {formatCrewTimelineDateRange(
                                phase.excluded_treatment.from_date,
                                phase.excluded_treatment.to_date,
                                'Excluded period',
                            )}
                        </Badge>
                    ) : null}
                </div>

                {phase.remarks.length > 0 ? (
                    <div className="mt-3 space-y-1 text-sm text-muted-foreground">
                        {phase.remarks.map((remark) => (
                            <p key={remark} className="leading-relaxed">
                                {remark}
                            </p>
                        ))}
                    </div>
                ) : null}

                <PhaseWarnings warnings={phase.warnings} />
            </div>
        </article>
    );
}

function AssignmentSection({
    section,
}: {
    section: CrewTimelineAssignmentSection;
}) {
    const { assignment, linkFromPrevious } = section;
    const details = [
        assignment.vessel
            ? { label: 'Vessel', value: assignment.vessel }
            : null,
        assignment.client
            ? { label: 'Client', value: assignment.client }
            : null,
        assignment.rank ? { label: 'Rank', value: assignment.rank } : null,
        { label: 'Source', value: assignment.source_label },
        assignment.status_label
            ? { label: 'Status', value: assignment.status_label }
            : null,
        assignment.previous_assignment_number
            ? {
                  label: 'Previous assignment',
                  value: assignment.previous_assignment_number,
              }
            : null,
    ].filter((detail): detail is { label: string; value: string } =>
        Boolean(detail),
    );

    return (
        <section className="rounded-2xl border border-border/70 bg-card/40 p-4 sm:p-5">
            {linkFromPrevious ? (
                <AssignmentLinkDivider divider={linkFromPrevious} />
            ) : null}

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Assignment
                    </p>
                    <h3 className="mt-0.5 text-base font-semibold">
                        {assignment.assignment_number ??
                            'Unnumbered assignment'}
                    </h3>
                </div>
                <Badge variant="outline" className="rounded-md">
                    {assignment.source_label}
                </Badge>
            </div>

            <dl className="mt-3 grid gap-2 sm:grid-cols-2">
                {details.map((detail) => (
                    <div
                        key={detail.label}
                        className="flex min-w-0 items-center gap-2 rounded-md border border-border/50 bg-muted/20 px-2.5 py-1.5 text-xs"
                    >
                        {detail.label === 'Vessel' ? (
                            <Ship
                                className="size-3 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}
                        <dt className="text-muted-foreground">
                            {detail.label}
                        </dt>
                        <dd className="truncate font-medium text-foreground">
                            {detail.value}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="mt-5">
                <div className="mb-3">
                    <h4 className="text-sm font-semibold">Phase timeline</h4>
                    <p className="text-xs text-muted-foreground">
                        One card per operational phase occurrence in this
                        assignment.
                    </p>
                </div>

                {assignment.phases.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border/70 px-5 py-8 text-center">
                        <CalendarDays
                            className="mx-auto size-7 text-muted-foreground/50"
                            aria-hidden
                        />
                        <p className="mt-2 text-sm font-medium">
                            No phase occurrences
                        </p>
                    </div>
                ) : (
                    <div>
                        {assignment.phases.map((phase, index) => (
                            <PhaseCard
                                key={`${assignment.id ?? 'na'}-${phase.id ?? index}-${index}`}
                                phase={phase}
                                isLast={index === assignment.phases.length - 1}
                            />
                        ))}
                    </div>
                )}
            </div>
        </section>
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

    const summary = summarizeCrewTimelineEmployee(employee);
    const sections = buildCrewTimelineAssignmentSections(
        employee.assignments ?? [],
    );
    const identityDetails = [
        employee.employee_number
            ? { label: 'Employee', value: employee.employee_number }
            : null,
        employee.rank ? { label: 'Rank', value: employee.rank } : null,
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
                                {[
                                    employee.employee_number
                                        ? `Employee ${employee.employee_number}`
                                        : null,
                                    employee.rank,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') ||
                                    'Payroll timeline review by assignment and phase.'}
                            </DialogDescription>
                            <p className="text-sm text-muted-foreground">
                                {formatAssignmentCountLabel(
                                    summary.assignmentCount,
                                )}
                            </p>
                        </div>
                    </div>

                    {identityDetails.length > 0 ? (
                        <dl className="mt-3 flex flex-wrap gap-2">
                            {identityDetails.map((detail) => (
                                <div
                                    key={detail.label}
                                    className="flex min-w-0 items-center gap-1.5 rounded-md border border-border/60 bg-muted/30 px-2 py-1 text-xs"
                                >
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

                <div className="grid shrink-0 grid-cols-2 border-b border-border/60 bg-muted/15 sm:grid-cols-3 lg:grid-cols-6">
                    <SummaryMetric
                        label="Assignments"
                        value={summary.assignmentCount}
                    />
                    <SummaryMetric
                        label="Phase occurrences"
                        value={summary.operationalPhaseCount}
                    />
                    <SummaryMetric
                        label="Payable periods"
                        value={summary.payablePeriodCount}
                    />
                    <SummaryMetric
                        label="Payable days"
                        value={summary.payableDays.toFixed(2)}
                    />
                    <SummaryMetric
                        label="Blocking warnings"
                        value={summary.blockingWarningCount}
                        tone={
                            summary.blockingWarningCount > 0
                                ? 'danger'
                                : 'success'
                        }
                    />
                    <SummaryMetric
                        label="Informational warnings"
                        value={summary.informationalWarningCount}
                        tone={
                            summary.informationalWarningCount > 0
                                ? 'warning'
                                : summary.blockingWarningCount === 0
                                  ? 'success'
                                  : 'default'
                        }
                    />
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto bg-muted/10 px-4 py-5 sm:px-6">
                    {summary.blockingWarningCount === 0 &&
                    summary.informationalWarningCount === 0 ? (
                        <div className="mb-4 flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
                            <CheckCircle2 className="size-4" aria-hidden />
                            No warnings on this employee timeline.
                        </div>
                    ) : null}

                    {sections.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border/70 px-5 py-10 text-center">
                            <CalendarDays
                                className="mx-auto size-8 text-muted-foreground/50"
                                aria-hidden
                            />
                            <p className="mt-3 text-sm font-medium">
                                No assignments in this preparation
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-5">
                            {sections.map((section) => (
                                <AssignmentSection
                                    key={
                                        section.assignment.id ??
                                        section.assignment.assignment_number ??
                                        'assignment'
                                    }
                                    section={section}
                                />
                            ))}
                        </div>
                    )}
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
