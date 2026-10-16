import { Link } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, FilePenLine, X } from 'lucide-react';
import { useMemo } from 'react';
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
import { buildAssignmentMilestoneRows } from '@/features/organization/crew/lib/assignment-milestone-display';
import {
    normalProgressSkippedStagesNote,
    resolveActiveLegacyPhaseContext,
} from '@/features/organization/crew/lib/crew-phase-visibility';
import { buildOperationalPhaseLines } from '@/features/organization/crew/lib/phase-timeline-display';
import { calendarDatesDiffer } from '@/features/organization/crew/lib/phase-timeline-math';
import { shouldHideP0MobilisationActivationLines } from '@/features/organization/crew/lib/phase-timeline-p0-expected-arrival';
import type { P0ExpectedArrivalContext } from '@/features/organization/crew/lib/phase-timeline-p0-expected-arrival';
import { buildPhaseMetadataRows } from '@/features/organization/crew/lib/phase-timeline-phase-metadata';
import type {
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
    PhaseTimelineItem,
} from '@/features/organization/crew/types';
import { useCompanyLocalDate } from '@/hooks/use-company-local-date';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showEmployeeTraining } from '@/routes/organization/employees/training';

function AssignmentMilestones({
    milestones,
    timeZone,
}: {
    milestones: ReturnType<typeof buildAssignmentMilestoneRows>;
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

function phaseStatusLine(
    phase: PhaseTimelineItem,
    index: number,
    p0ExpectedArrivalContext: P0ExpectedArrivalContext,
): string {
    const waiting = shouldHideP0MobilisationActivationLines(
        phase,
        p0ExpectedArrivalContext,
    );

    const base = waiting
        ? `${phase.status_label} · Waiting for crew arrival`
        : phase.status_label;

    const parts = [base];

    if (index === 0) {
        parts.push('Sequence start');
    }

    return parts.join(' · ');
}

function buildP0ExpectedArrivalContext(
    assignment: CrewAssignmentDetail,
    phase: PhaseTimelineItem,
    isCurrent: boolean,
): P0ExpectedArrivalContext {
    return {
        assignmentStatus: assignment.status,
        isCurrentPhase: isCurrent,
        plannedArrivalAt: assignment.planned_arrival_at,
    };
}

function PhaseDateLines({
    phase,
    today,
    timeZone,
    isCurrent,
    assignmentStatus,
    plannedArrivalAt,
    plannedJoinAt,
    plannedSignoffAt,
    plannedTravelAt,
}: {
    phase: PhaseTimelineItem;
    today: string;
    timeZone?: string | null;
    isCurrent: boolean;
    assignmentStatus?: string | null;
    plannedArrivalAt?: string | null;
    plannedJoinAt?: string | null;
    plannedSignoffAt?: string | null;
    plannedTravelAt?: string | null;
}): ReactElement {
    const lines = buildOperationalPhaseLines(phase, {
        isCurrent,
        todayIso: today,
        timeZone,
        assignmentStatus,
        plannedArrivalAt,
        plannedJoinAt,
        plannedSignoffAt,
        plannedTravelAt,
        formatDate: formatDisplayDate,
    });

    if (lines.length === 0) {
        return <></>;
    }

    return (
        <div className="space-y-1 text-xs text-muted-foreground">
            {lines.map((line) => (
                <p key={`${line.label}-${line.value}`}>
                    <span className="font-medium text-foreground/70">
                        {`${line.label}: `}
                    </span>
                    <span
                        className={
                            line.planned
                                ? 'font-semibold text-foreground'
                                : 'font-medium text-foreground'
                        }
                    >
                        {line.value}
                    </span>
                    {line.planned ? (
                        <span className="ml-1 text-[10px] tracking-wide text-muted-foreground uppercase">
                            Planned
                        </span>
                    ) : null}
                </p>
            ))}
        </div>
    );
}

function PhaseTimelineRow({
    phase,
    index,
    isCurrent,
    today,
    timeZone,
    assignmentStatus,
    can,
    employeeId,
    correctablePhaseIds,
    onCorrect,
    onCancelPending,
    p0ExpectedArrivalContext,
    plannedArrivalAt,
    plannedJoinAt,
    plannedSignoffAt,
    plannedTravelAt,
}: {
    phase: PhaseTimelineItem;
    index: number;
    isCurrent: boolean;
    today: string;
    timeZone?: string | null;
    assignmentStatus?: string | null;
    p0ExpectedArrivalContext: P0ExpectedArrivalContext;
    plannedArrivalAt?: string | null;
    plannedJoinAt?: string | null;
    plannedSignoffAt?: string | null;
    plannedTravelAt?: string | null;
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
    const metadataRows = buildPhaseMetadataRows(phase);

    return (
        <li className="relative pb-5 last:pb-0">
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

            <div className="space-y-2.5 rounded-lg border border-border/40 bg-card/40 px-3 py-3 dark:bg-white/[0.02]">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0 space-y-1">
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
                            {(phase.phase_code === 'p1b' ||
                                phase.phase_code === 'p2b') &&
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
                            {phaseStatusLine(
                                phase,
                                index,
                                p0ExpectedArrivalContext,
                            )}
                        </p>
                    </div>
                </div>

                <PhaseDateLines
                    phase={phase}
                    today={today}
                    timeZone={timeZone}
                    isCurrent={isCurrent}
                    assignmentStatus={assignmentStatus}
                    plannedArrivalAt={plannedArrivalAt}
                    plannedJoinAt={plannedJoinAt}
                    plannedSignoffAt={plannedSignoffAt}
                    plannedTravelAt={plannedTravelAt}
                />
                {phase.remarks ? (
                    <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                        Remarks: {phase.remarks}
                    </p>
                ) : null}
                {metadataRows.length > 0 ? (
                    <dl className="grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                        {metadataRows.map((row) => (
                            <div key={row.key}>
                                <dt className="font-medium text-foreground/80">
                                    {row.label}
                                </dt>
                                <dd>{row.value}</dd>
                            </div>
                        ))}
                    </dl>
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
    const timeZone = assignment.company_timezone;
    const today = useCompanyLocalDate(timeZone);

    const milestones = useMemo(
        () => buildAssignmentMilestoneRows(assignment),
        [assignment],
    );

    const legacyContext = resolveActiveLegacyPhaseContext(
        assignment.current_phase?.code ?? null,
        assignment.phase_timeline,
    );
    const skippedNote = normalProgressSkippedStagesNote(
        assignment.current_phase?.code ?? null,
        assignment.phase_timeline,
    );

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="space-y-3 pb-3">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle className="text-base">
                            Phase Timeline
                        </CardTitle>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Recorded movements, with the next planned milestone
                            while a phase is still active
                        </p>
                    </div>
                </div>
                {legacyContext || skippedNote ? (
                    <div className="space-y-1.5">
                        {legacyContext ? (
                            <p className="rounded-md border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                                {legacyContext}
                            </p>
                        ) : null}
                        {skippedNote ? (
                            <p className="rounded-md border border-border/50 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                                {skippedNote}
                            </p>
                        ) : null}
                    </div>
                ) : null}
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
                ) : (
                    <ol className="relative space-y-0 border-l border-border/70 pl-5">
                        {assignment.phase_timeline.map((phase, index) => {
                            const isCurrent =
                                assignment.current_phase?.id === phase.id;

                            return (
                                <PhaseTimelineRow
                                    key={phase.id}
                                    phase={phase}
                                    index={index}
                                    isCurrent={isCurrent}
                                    today={today}
                                    timeZone={timeZone}
                                    assignmentStatus={assignment.status}
                                    can={can}
                                    employeeId={assignment.employee?.id}
                                    correctablePhaseIds={correctablePhaseIds}
                                    onCorrect={onCorrect}
                                    onCancelPending={onCancelPending}
                                    plannedArrivalAt={
                                        assignment.planned_arrival_at
                                    }
                                    plannedJoinAt={assignment.planned_join_at}
                                    plannedSignoffAt={
                                        assignment.planned_signoff_at
                                    }
                                    plannedTravelAt={
                                        assignment.planned_travel_at
                                    }
                                    p0ExpectedArrivalContext={buildP0ExpectedArrivalContext(
                                        assignment,
                                        phase,
                                        isCurrent,
                                    )}
                                />
                            );
                        })}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
