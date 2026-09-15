import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    History,
    MapPinned,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactElement } from 'react';
import { crewPhaseDescription } from '@/features/organization/crew/lib/crew-phase-descriptions';
import type {
    CorrectionsSummary,
    CrewAssignmentDetail,
} from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

type SummaryTone = 'critical' | 'warning' | 'healthy' | 'neutral';

function SummaryCard({
    label,
    value,
    detail,
    icon: Icon,
    tone = 'neutral',
}: {
    label: string;
    value: string;
    detail: string;
    icon: LucideIcon;
    tone?: SummaryTone;
}): ReactElement {
    return (
        <article
            className={cn(
                'min-w-0 rounded-xl border bg-card/80 p-4 shadow-xs dark:bg-white/2',
                tone === 'critical' && 'border-destructive/30',
                tone === 'warning' && 'border-warning/30',
                tone === 'healthy' && 'border-emerald-500/25',
                tone === 'neutral' && 'border-border/60 dark:border-white/5',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p
                        className={cn(
                            'mt-1 truncate text-lg font-bold text-foreground',
                            tone === 'critical' && 'text-destructive',
                            tone === 'warning' && 'text-warning',
                            tone === 'healthy' &&
                                'text-emerald-600 dark:text-emerald-400',
                        )}
                        title={value}
                    >
                        {value}
                    </p>
                </div>
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-lg',
                        tone === 'critical' &&
                            'bg-destructive/10 text-destructive',
                        tone === 'warning' && 'bg-warning/10 text-warning',
                        tone === 'healthy' &&
                            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                        tone === 'neutral' &&
                            'bg-muted/50 text-muted-foreground',
                    )}
                >
                    <Icon className="size-4" />
                </span>
            </div>
            <p className="mt-2 line-clamp-2 text-[11px] leading-4 text-muted-foreground/75">
                {detail}
            </p>
        </article>
    );
}

function nextMilestone(assignment: CrewAssignmentDetail): {
    label: string;
    value: string;
    detail: string;
    tone: SummaryTone;
} {
    if (assignment.current_phase?.code === 'p4') {
        const days = assignment.days_until_signoff;

        return {
            label: 'Next milestone',
            value: formatDisplayDate(assignment.planned_signoff_at),
            detail:
                days === null
                    ? 'Planned sign-off date'
                    : days < 0
                      ? `${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} overdue`
                      : `${days} day${days === 1 ? '' : 's'} until planned sign-off`,
            tone: days !== null && days < 0 ? 'critical' : 'neutral',
        };
    }

    if (
        ['p0', 'p1', 'p2a', 'p2b', 'p3'].includes(
            assignment.current_phase?.code ?? '',
        )
    ) {
        return {
            label: 'Expected Join',
            value: formatDisplayDate(assignment.planned_join_at),
            detail:
                assignment.recommended_action?.label ??
                'Expected vessel joining date',
            tone: 'neutral',
        };
    }

    return {
        label: 'Next action',
        value: assignment.recommended_action?.label ?? 'No action queued',
        detail:
            assignment.recommended_action?.reason ??
            'No operational milestone currently scheduled',
        tone: assignment.recommended_action ? 'warning' : 'healthy',
    };
}

export function CrewAssignmentOperationalSummary({
    assignment,
    corrections,
}: {
    assignment: CrewAssignmentDetail;
    corrections?: CorrectionsSummary;
}): ReactElement {
    const completedPhases = assignment.phase_timeline.filter(
        (phase) => phase.status === 'completed',
    ).length;
    const actualMovementDates = assignment.phase_timeline.filter(
        (phase) => phase.actual_start_at || phase.actual_end_at,
    ).length;
    const readinessProblems =
        assignment.mobilisation_readiness?.problems.length ?? 0;
    const attentionSignals =
        assignment.warnings.length +
        readinessProblems +
        (corrections?.pending_count ?? 0);
    const milestone = nextMilestone(assignment);
    const phaseAge = assignment.days_in_phase;

    return (
        <section className="mb-6 space-y-3" aria-labelledby="assignment-pulse">
            <div>
                <h2
                    id="assignment-pulse"
                    className="text-sm font-semibold text-foreground"
                >
                    Assignment pulse
                </h2>
                <p className="text-xs text-muted-foreground">
                    Current operating position and immediate assignment health.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    label="Current station"
                    value={assignment.vessel?.name ?? 'No vessel assigned'}
                    detail={
                        [assignment.rank?.name, assignment.client?.name]
                            .filter(Boolean)
                            .join(' · ') || 'Position details are not assigned'
                    }
                    icon={MapPinned}
                />
                <SummaryCard
                    label="Phase tenure"
                    value={
                        phaseAge === null
                            ? 'Not started'
                            : `${phaseAge} day${phaseAge === 1 ? '' : 's'}`
                    }
                    detail={
                        assignment.current_phase
                            ? (crewPhaseDescription(
                                  assignment.current_phase.code,
                              ) ??
                              `${assignment.current_phase.code.toUpperCase()} · ${assignment.current_phase.label}`)
                            : 'No current phase recorded'
                    }
                    icon={History}
                    tone={
                        phaseAge !== null && phaseAge > 90
                            ? 'warning'
                            : 'neutral'
                    }
                />
                <SummaryCard
                    label={milestone.label}
                    value={milestone.value}
                    detail={milestone.detail}
                    icon={CalendarClock}
                    tone={milestone.tone}
                />
                <SummaryCard
                    label="Operational attention"
                    value={
                        attentionSignals === 0
                            ? 'Clear'
                            : `${attentionSignals} signals`
                    }
                    detail={
                        attentionSignals === 0
                            ? `${completedPhases} completed phase${completedPhases === 1 ? '' : 's'} · ${actualMovementDates} actual movement record${actualMovementDates === 1 ? '' : 's'}`
                            : `${assignment.warnings.length} warning${assignment.warnings.length === 1 ? '' : 's'} · ${readinessProblems} readiness · ${corrections?.pending_count ?? 0} correction`
                    }
                    icon={attentionSignals === 0 ? CheckCircle2 : AlertTriangle}
                    tone={
                        assignment.warnings.some(
                            (warning) => warning.severity === 'critical',
                        )
                            ? 'critical'
                            : attentionSignals > 0
                              ? 'warning'
                              : 'healthy'
                    }
                />
            </div>
        </section>
    );
}
