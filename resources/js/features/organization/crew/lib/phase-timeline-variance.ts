import type { PhaseTimelineItem } from '../types.ts';
import {
    formatSignedDayPhrase,
    inclusiveDurationDays,
    signedDayDelta,
} from './phase-timeline-math.ts';

export type PhaseVarianceTone =
    | 'neutral'
    | 'success'
    | 'warning'
    | 'pending'
    | 'muted';

export interface PhaseVarianceSummary {
    startVarianceDays: number | null;
    endVarianceDays: number | null;
    durationVarianceDays: number | null;
    plannedDurationDays: number | null;
    actualDurationDays: number | null;
    /** Confirmed actual duration only — never invents an end from today. */
    hasConfirmedActualEnd: boolean;
    isInProgress: boolean;
    isNotStarted: boolean;
    hasPlannedDates: boolean;
    hasActualDates: boolean;
    hasDeviation: boolean;
    tone: PhaseVarianceTone;
    headline: string;
    details: string[];
}

function phaseIsActive(phase: PhaseTimelineItem): boolean {
    return phase.status === 'active' || phase.status === 'planned';
}

export function summarizePhaseVariance(
    phase: PhaseTimelineItem,
): PhaseVarianceSummary {
    const hasPlannedDates = Boolean(
        phase.planned_start_at || phase.planned_end_at,
    );
    const hasActualDates = Boolean(
        phase.actual_start_at || phase.actual_end_at,
    );
    const hasConfirmedActualEnd = Boolean(phase.actual_end_at);
    const isNotStarted = !phase.actual_start_at;
    const isInProgress =
        Boolean(phase.actual_start_at) &&
        !hasConfirmedActualEnd &&
        phaseIsActive(phase);

    const plannedDurationDays = inclusiveDurationDays(
        phase.planned_start_at,
        phase.planned_end_at,
    );
    // Only confirmed ends contribute to actual duration / duration variance.
    const actualDurationDays = hasConfirmedActualEnd
        ? inclusiveDurationDays(phase.actual_start_at, phase.actual_end_at)
        : null;

    const startVarianceDays = signedDayDelta(
        phase.planned_start_at,
        phase.actual_start_at,
    );
    const endVarianceDays = hasConfirmedActualEnd
        ? signedDayDelta(phase.planned_end_at, phase.actual_end_at)
        : null;
    const durationVarianceDays =
        plannedDurationDays !== null && actualDurationDays !== null
            ? actualDurationDays - plannedDurationDays
            : null;

    const details: string[] = [];
    const startPhrase = formatSignedDayPhrase(
        startVarianceDays,
        'early',
        'late',
        'on time',
    );

    if (startPhrase && startVarianceDays !== null) {
        if (startVarianceDays === 0) {
            details.push('Started on time');
        } else if (startVarianceDays < 0) {
            details.push(`Started ${startPhrase}`);
        } else {
            details.push(`Started ${startPhrase}`);
        }
    }

    if (hasConfirmedActualEnd) {
        const endPhrase = formatSignedDayPhrase(
            endVarianceDays,
            'early',
            'late',
            'on time',
        );

        if (endPhrase && endVarianceDays !== null) {
            if (endVarianceDays === 0) {
                details.push('Completed on time');
            } else if (endVarianceDays < 0) {
                details.push(`Completed ${endPhrase}`);
            } else {
                details.push(`Completed ${endPhrase}`);
            }
        }
    }

    if (durationVarianceDays !== null && durationVarianceDays !== 0) {
        const abs = Math.abs(durationVarianceDays);
        const unit = abs === 1 ? 'day' : 'days';
        details.push(
            durationVarianceDays > 0
                ? `Duration ${abs} ${unit} longer`
                : `Duration ${abs} ${unit} shorter`,
        );
    }

    const hasDeviation =
        (startVarianceDays !== null && startVarianceDays !== 0) ||
        (endVarianceDays !== null && endVarianceDays !== 0) ||
        (durationVarianceDays !== null && durationVarianceDays !== 0);

    let tone: PhaseVarianceTone = 'neutral';
    let headline = 'No plan/actual comparison';

    if (!hasPlannedDates && hasActualDates) {
        tone = phase.status === 'completed' ? 'success' : 'pending';
        headline = isInProgress
            ? 'In progress · no planned dates'
            : phase.status === 'completed'
              ? 'Completed · no planned dates'
              : 'Actual recorded · no planned dates';
    } else if (hasPlannedDates && isNotStarted) {
        tone = 'pending';
        headline = 'Not started';
    } else if (isInProgress) {
        tone = hasDeviation ? 'warning' : 'pending';
        headline = hasDeviation
            ? (details[0] ?? 'In progress with variance')
            : 'In progress';
    } else if (phase.status === 'completed' || hasConfirmedActualEnd) {
        if (!hasPlannedDates) {
            tone = 'success';
            headline = 'Completed';
        } else if (!hasDeviation) {
            tone = 'success';
            headline = 'On time';
        } else {
            tone = 'warning';
            headline = details.join('; ') || 'Completed with variance';
        }
    } else if (hasPlannedDates && !hasActualDates) {
        tone = 'muted';
        headline = 'Planned · awaiting actual';
    }

    return {
        startVarianceDays,
        endVarianceDays,
        durationVarianceDays,
        plannedDurationDays,
        actualDurationDays,
        hasConfirmedActualEnd,
        isInProgress,
        isNotStarted,
        hasPlannedDates,
        hasActualDates,
        hasDeviation,
        tone,
        headline,
        details,
    };
}
