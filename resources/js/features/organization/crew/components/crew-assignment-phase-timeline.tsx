import { Link } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, FilePenLine, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import type { PhaseTimelineDisplayMode } from '@/features/organization/crew/lib/phase-timeline-math';
import {
    PHASE_TIMELINE_DISPLAY_MODES,
    calendarDatesDiffer,
    collectTimelineDates,
    formatDayCount,
    phaseBarStyle,
    phaseHasPlannedDates,
    phasesHavePlannedDates,
    resolveSharedTimelineRange,
    shouldIncludeTodayInTimelineRange,
} from '@/features/organization/crew/lib/phase-timeline-math';
import type { PhaseVarianceTone } from '@/features/organization/crew/lib/phase-timeline-variance';
import { summarizePhaseVariance } from '@/features/organization/crew/lib/phase-timeline-variance';
import type {
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
    PhaseTimelineItem,
} from '@/features/organization/crew/types';
import { formatDisplayDate, nowInCompanyDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showEmployeeTraining } from '@/routes/organization/employees/training';

interface AssignmentMilestone {
    label: string;
    planned: string | null | undefined;
    actual: string | null | undefined;
}

function SegmentedControl({
    value,
    onChange,
}: {
    value: PhaseTimelineDisplayMode;
    onChange: (value: PhaseTimelineDisplayMode) => void;
}): ReactElement {
    return (
        <div
            className="inline-flex w-full max-w-md rounded-lg border border-border/60 bg-muted/10 p-0.5 sm:w-auto"
            role="tablist"
            aria-label="Phase timeline display mode"
        >
            {PHASE_TIMELINE_DISPLAY_MODES.map((option) => {
                const selected = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        className={cn(
                            'flex-1 rounded-md px-2.5 py-1.5 text-[11px] font-semibold whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:flex-none',
                            selected
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => onChange(option.value)}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

function varianceToneClass(tone: PhaseVarianceTone): string {
    switch (tone) {
        case 'success':
            return 'text-emerald-700 dark:text-emerald-300';
        case 'warning':
            return 'text-amber-700 dark:text-amber-300';
        case 'pending':
            return 'text-sky-700 dark:text-sky-300';
        case 'muted':
            return 'text-muted-foreground';
        default:
            return 'text-muted-foreground';
    }
}

function TimelineBarTrack({
    label,
    style,
    tone,
    openEnded = false,
}: {
    label: string;
    style: { left: string; width: string };
    tone: 'planned' | 'actual-success' | 'actual-warning' | 'actual-pending';
    openEnded?: boolean;
}): ReactElement {
    return (
        <div className="flex items-center gap-2">
            <span className="w-14 shrink-0 text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-muted/40">
                <span
                    className={cn(
                        'absolute top-0 h-full rounded-full',
                        tone === 'planned' &&
                            'bg-slate-400/80 dark:bg-slate-500/80',
                        tone === 'actual-success' && 'bg-emerald-500',
                        tone === 'actual-warning' && 'bg-amber-500',
                        tone === 'actual-pending' &&
                            'bg-sky-500/80 dark:bg-sky-400/70',
                        openEnded && 'rounded-r-none opacity-80',
                    )}
                    style={{ left: style.left, width: style.width }}
                    title={`${label} period`}
                />
            </div>
        </div>
    );
}

function AssignmentMilestones({
    milestones,
    timeZone,
}: {
    milestones: AssignmentMilestone[];
    timeZone?: string | null;
}): ReactElement | null {
    const visible = milestones.filter((row) => row.planned || row.actual);

    if (visible.length === 0) {
        return null;
    }

    return (
        <Collapsible defaultOpen={false}>
            <CollapsibleTrigger className="group flex w-full items-center justify-between rounded-md border border-border/50 px-3 py-2 text-left text-xs font-medium text-muted-foreground hover:bg-muted/30">
                <span>Assignment milestones</span>
                <ChevronDown className="size-3.5 transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-2">
                <div className="overflow-hidden rounded-md border border-border/40">
                    <div className="grid grid-cols-[1fr_auto_auto] gap-x-3 border-b border-border/40 bg-muted/20 px-3 py-1.5">
                        <span />
                        <span className="text-[10px] font-bold tracking-wide text-muted-foreground/70 uppercase">
                            Planned
                        </span>
                        <span className="text-[10px] font-bold tracking-wide text-muted-foreground/70 uppercase">
                            Actual
                        </span>
                    </div>
                    {visible.map((row) => {
                        const hasVariance = calendarDatesDiffer(
                            row.planned,
                            row.actual,
                            timeZone,
                        );

                        return (
                            <div
                                key={row.label}
                                className="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 border-b border-border/30 px-3 py-2 last:border-b-0"
                            >
                                <span className="text-[11px] font-medium text-muted-foreground">
                                    {row.label}
                                </span>
                                <span className="text-right text-[11px] text-muted-foreground/70">
                                    {formatDisplayDate(row.planned)}
                                </span>
                                <span
                                    className={cn(
                                        'text-right text-[11px] font-medium',
                                        hasVariance
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : row.actual
                                              ? 'text-foreground'
                                              : 'text-muted-foreground/40',
                                    )}
                                >
                                    {formatDisplayDate(row.actual)}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function PhaseTimelineRow({
    phase,
    index,
    isCurrent,
    mode,
    range,
    today,
    timeZone,
    can,
    employeeId,
    correctablePhaseIds,
    onCorrect,
    onCancelPending,
}: {
    phase: PhaseTimelineItem;
    index: number;
    isCurrent: boolean;
    mode: PhaseTimelineDisplayMode;
    range: { from: string; to: string } | null;
    today: string;
    timeZone?: string | null;
    can: Pick<
        CrewAssignmentPagePermissions,
        | 'view_corrections'
        | 'request_correction'
        | 'override_corrections'
        | 'view_training'
    >;
    employeeId: number | null | undefined;
    correctablePhaseIds: Set<number>;
    onCorrect: (phaseId: number) => void;
    onCancelPending: (correctionId: number) => void;
}): ReactElement {
    const variance = summarizePhaseVariance(phase, timeZone);
    const showPlanned = mode !== 'actual_only';
    const showActual = mode !== 'planned_only';
    const hasPlanned = phaseHasPlannedDates(phase);
    const hasActual = Boolean(phase.actual_start_at || phase.actual_end_at);
    const [detailsOpen, setDetailsOpen] = useState(false);

    const plannedStyle =
        range && showPlanned && hasPlanned
            ? phaseBarStyle(
                  phase.planned_start_at,
                  phase.planned_end_at,
                  range.from,
                  range.to,
                  { timeZone },
              )
            : null;

    const actualStyle =
        range && showActual && hasActual
            ? phaseBarStyle(
                  phase.actual_start_at,
                  phase.actual_end_at,
                  range.from,
                  range.to,
                  {
                      openEndedVisualEnd: variance.isInProgress ? today : null,
                      timeZone,
                  },
              )
            : null;

    const actualTone =
        mode === 'plan_vs_actual' && variance.tone === 'warning'
            ? 'actual-warning'
            : variance.isInProgress || variance.isNotStarted
              ? 'actual-pending'
              : 'actual-success';

    const showPlannedBar =
        plannedStyle !== null && !('display' in plannedStyle);
    const showActualBar = actualStyle !== null && !('display' in actualStyle);
    const showMissingPlannedMessage = showPlanned && !hasPlanned;
    const showBarPanel =
        range !== null &&
        (showPlannedBar || showActualBar || showMissingPlannedMessage);

    const hasExpandableDetails = Boolean(
        phase.remarks ||
        (phase.details && Object.keys(phase.details).length > 0) ||
        variance.details.length > 1 ||
        variance.plannedDurationDays !== null ||
        variance.actualDurationDays !== null,
    );

    return (
        <li className="relative pb-6 last:pb-0">
            <span
                className={cn(
                    'absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full border-2 border-background',
                    isCurrent
                        ? 'bg-primary'
                        : phase.status === 'completed'
                          ? 'bg-emerald-500'
                          : 'bg-muted-foreground/40',
                )}
                aria-hidden
            />

            <div className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <CrewPhaseBadge
                                code={phase.phase_code}
                                label={phase.phase_label}
                                status={phase.status}
                            />
                            {isCurrent ? (
                                <Badge variant="outline">Current</Badge>
                            ) : null}
                            {(can.view_corrections ||
                                can.request_correction ||
                                can.override_corrections) &&
                            phase.has_pending_correction ? (
                                <Badge variant="warning">
                                    Pending Correction
                                </Badge>
                            ) : can.view_corrections &&
                              phase.has_approved_correction ? (
                                <Badge variant="secondary">Corrected</Badge>
                            ) : null}
                            {can.override_corrections &&
                            !phase.has_pending_correction &&
                            correctablePhaseIds.has(phase.id) ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 px-2 text-xs font-medium text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:text-amber-400"
                                    onClick={() => onCorrect(phase.id)}
                                >
                                    <FilePenLine className="mr-1 size-3" />
                                    Correct
                                </Button>
                            ) : null}
                            {phase.can_cancel_pending &&
                            phase.own_pending_correction_id ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 px-2 text-xs font-medium text-destructive hover:bg-destructive/10"
                                    onClick={() => {
                                        if (phase.own_pending_correction_id) {
                                            onCancelPending(
                                                phase.own_pending_correction_id,
                                            );
                                        }
                                    }}
                                >
                                    <X className="mr-1 size-3" />
                                    Cancel Request
                                </Button>
                            ) : null}
                            {phase.phase_code === 'p2b' &&
                            phase.employee_training_id ? (
                                can.view_training && employeeId ? (
                                    <Link
                                        href={showEmployeeTraining.url({
                                            employee: employeeId,
                                            training:
                                                phase.employee_training_id,
                                        })}
                                        className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300"
                                    >
                                        <CheckCircle2 className="size-3 text-emerald-600 dark:text-emerald-400" />
                                        Added to Employee Training
                                    </Link>
                                ) : (
                                    <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                        <CheckCircle2 className="size-3 text-emerald-600 dark:text-emerald-400" />
                                        Added to Employee Training
                                    </span>
                                )
                            ) : null}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {phase.status_label}
                            {index === 0 ? ' · Sequence start' : ''}
                            {phase.phase_code === 'p2b' && phase.details?.course
                                ? ` · ${String(phase.details.course)}`
                                : ''}
                        </p>
                        {mode === 'plan_vs_actual' ? (
                            <p
                                className={cn(
                                    'text-xs font-medium',
                                    varianceToneClass(variance.tone),
                                )}
                            >
                                {variance.headline}
                            </p>
                        ) : null}
                    </div>

                    <div className="min-w-[10rem] space-y-1 text-right text-xs text-muted-foreground">
                        {showActual ? (
                            <div>
                                Actual:{' '}
                                {formatDisplayDate(phase.actual_start_at)}
                                {phase.actual_end_at
                                    ? ` → ${formatDisplayDate(phase.actual_end_at)}`
                                    : variance.isInProgress
                                      ? ' → in progress'
                                      : ''}
                                {variance.actualDurationDays !== null
                                    ? ` · ${formatDayCount(variance.actualDurationDays)}`
                                    : ''}
                            </div>
                        ) : null}
                        {showPlanned && hasPlanned ? (
                            <div>
                                Planned:{' '}
                                {formatDisplayDate(phase.planned_start_at)}
                                {phase.planned_end_at
                                    ? ` → ${formatDisplayDate(phase.planned_end_at)}`
                                    : ''}
                                {variance.plannedDurationDays !== null
                                    ? ` · ${formatDayCount(variance.plannedDurationDays)}`
                                    : ''}
                            </div>
                        ) : null}
                        {showMissingPlannedMessage ? (
                            <div className="text-muted-foreground/60">
                                No planned dates
                            </div>
                        ) : null}
                    </div>
                </div>

                {showBarPanel && range ? (
                    <div className="space-y-1.5 rounded-lg border border-border/40 bg-muted/10 px-3 py-2.5">
                        {showMissingPlannedMessage ? (
                            <p className="text-xs text-muted-foreground">
                                {mode === 'planned_only'
                                    ? 'No planned dates recorded for this phase.'
                                    : 'No planned dates — variance cannot be calculated.'}
                            </p>
                        ) : null}
                        {showPlannedBar && plannedStyle ? (
                            <TimelineBarTrack
                                label="Planned"
                                style={plannedStyle}
                                tone="planned"
                            />
                        ) : null}
                        {showActualBar && actualStyle ? (
                            <TimelineBarTrack
                                label="Actual"
                                style={actualStyle}
                                tone={actualTone}
                                openEnded={variance.isInProgress}
                            />
                        ) : null}
                        {showPlannedBar || showActualBar ? (
                            <div className="flex justify-between pt-0.5 text-[10px] text-muted-foreground/70">
                                <span>{formatDisplayDate(range.from)}</span>
                                <span>{formatDisplayDate(range.to)}</span>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {hasExpandableDetails ? (
                    <Collapsible
                        open={detailsOpen}
                        onOpenChange={setDetailsOpen}
                    >
                        <CollapsibleTrigger className="group inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground hover:text-foreground">
                            Phase details
                            <ChevronDown className="size-3 transition-transform group-data-[state=open]:rotate-180" />
                        </CollapsibleTrigger>
                        <CollapsibleContent className="mt-2 space-y-2 rounded-md border border-border/40 bg-background/60 px-3 py-2 text-xs text-muted-foreground">
                            {mode === 'plan_vs_actual' &&
                            variance.details.length > 0 ? (
                                <ul className="list-disc space-y-0.5 pl-4">
                                    {variance.details.map((detail) => (
                                        <li key={detail}>{detail}</li>
                                    ))}
                                </ul>
                            ) : null}
                            <div className="grid gap-1 sm:grid-cols-2">
                                <p>
                                    Planned duration:{' '}
                                    {formatDayCount(
                                        variance.plannedDurationDays,
                                    )}
                                </p>
                                <p>
                                    Actual duration:{' '}
                                    {variance.hasConfirmedActualEnd
                                        ? formatDayCount(
                                              variance.actualDurationDays,
                                          )
                                        : variance.isInProgress
                                          ? 'In progress'
                                          : '—'}
                                </p>
                            </div>
                            {phase.remarks ? (
                                <p className="whitespace-pre-wrap">
                                    Remarks: {phase.remarks}
                                </p>
                            ) : null}
                            {phase.details &&
                            Object.keys(phase.details).length > 0 ? (
                                <dl className="grid gap-1 sm:grid-cols-2">
                                    {Object.entries(phase.details).map(
                                        ([key, value]) => (
                                            <div key={key}>
                                                <dt className="font-medium text-foreground/80">
                                                    {key.replaceAll('_', ' ')}
                                                </dt>
                                                <dd>
                                                    {value === null ||
                                                    value === undefined
                                                        ? '—'
                                                        : String(value)}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            ) : null}
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </div>
        </li>
    );
}

export function CrewAssignmentPhaseTimeline({
    assignment,
    can,
    correctablePhaseIds,
    onCorrect,
    onCancelPending,
}: {
    assignment: CrewAssignmentDetail;
    can: CrewAssignmentPagePermissions;
    correctablePhaseIds: Set<number>;
    onCorrect: (phaseId: number) => void;
    onCancelPending: (correctionId: number) => void;
}): ReactElement {
    const [mode, setMode] =
        useState<PhaseTimelineDisplayMode>('plan_vs_actual');
    const timeZone = assignment.company_timezone;

    const today = useMemo(() => nowInCompanyDate(timeZone), [timeZone]);

    const includeToday = useMemo(
        () =>
            shouldIncludeTodayInTimelineRange(assignment.phase_timeline, mode),
        [assignment.phase_timeline, mode],
    );

    const range = useMemo(() => {
        const dates = collectTimelineDates(
            assignment.phase_timeline,
            mode,
            timeZone,
        );

        return resolveSharedTimelineRange(dates, {
            todayIso: today,
            includeToday,
            timeZone,
        });
    }, [assignment.phase_timeline, includeToday, mode, timeZone, today]);

    const milestones: AssignmentMilestone[] = useMemo(
        () => [
            {
                label: 'Arrival',
                planned: assignment.planned_arrival_at,
                actual: assignment.actual_arrival_at,
            },
            {
                label: 'Vessel Join',
                planned: assignment.planned_join_at,
                actual: assignment.actual_join_at,
            },
            {
                label: 'Travel',
                planned: assignment.planned_travel_at,
                actual: null,
            },
            {
                label: 'Sign-Off',
                planned: assignment.planned_signoff_at,
                actual: assignment.actual_disembarkation_at,
            },
            {
                label: 'Started',
                planned: null,
                actual: assignment.started_at,
            },
            {
                label: 'Closed',
                planned: null,
                actual: assignment.closed_at,
            },
        ],
        [assignment],
    );

    const hasAnyPlannedDates = phasesHavePlannedDates(
        assignment.phase_timeline,
    );
    const plannedOnlyEmpty =
        mode === 'planned_only' &&
        assignment.phase_timeline.length > 0 &&
        !hasAnyPlannedDates;

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="space-y-3 pb-3">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle className="text-base">
                            Phase Timeline
                        </CardTitle>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Planned and actual movements on one shared calendar
                            scale
                        </p>
                    </div>
                    <SegmentedControl value={mode} onChange={setMode} />
                </div>
                <AssignmentMilestones
                    milestones={milestones}
                    timeZone={timeZone}
                />
            </CardHeader>
            <CardContent>
                {assignment.phase_timeline.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No phase timeline recorded yet.
                    </p>
                ) : plannedOnlyEmpty ? (
                    <p className="text-sm text-muted-foreground">
                        No planned phase dates recorded for this assignment.
                        Switch to Actual Only or Plan vs Actual to review
                        recorded movements.
                    </p>
                ) : (
                    <ol className="relative space-y-0 border-l border-border/70 pl-5">
                        {assignment.phase_timeline.map((phase, index) => (
                            <PhaseTimelineRow
                                key={phase.id}
                                phase={phase}
                                index={index}
                                isCurrent={
                                    assignment.current_phase?.id === phase.id
                                }
                                mode={mode}
                                range={range}
                                today={today}
                                timeZone={timeZone}
                                can={can}
                                employeeId={assignment.employee?.id}
                                correctablePhaseIds={correctablePhaseIds}
                                onCorrect={onCorrect}
                                onCancelPending={onCancelPending}
                            />
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
