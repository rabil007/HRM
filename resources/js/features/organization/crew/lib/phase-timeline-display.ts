import type { PhaseTimelineItem } from '../types.ts';
import type { PhaseTimelineDisplayMode } from './phase-timeline-math.ts';
import {
    daysPastPlannedEnd,
    daysUntilPlannedStart,
    elapsedWholeCalendarDays,
    formatDayCount,
    formatElapsedDayPhrase,
    phaseHasPlannedDates,
} from './phase-timeline-math.ts';
import { summarizePhaseVariance } from './phase-timeline-variance.ts';

export type PhaseDateLineSummary = {
    showPlanned: boolean;
    showActual: boolean;
    showVariance: boolean;
    hasPlanned: boolean;
    plannedStartLabel: string;
    plannedEndLabel: string | null;
    actualStartLabel: string;
    actualEndLabel: string | null;
    durationParts: string[];
    plannedDurationLabel: string;
    actualDurationLabel: string;
};

function formatDateLabel(
    value: string | null | undefined,
    fallback: string,
    formatter: (value: string | null | undefined) => string,
): string {
    if (!value) {
        return fallback;
    }

    const formatted = formatter(value);

    return formatted === '—' ? fallback : formatted;
}

/**
 * Mode-aware Phase Timeline date/duration copy. Planned overdue and planned
 * windows must never appear in Actual Only; actual windows must never appear
 * in Planned Only.
 */
export function buildPhaseDateLineSummary(
    phase: PhaseTimelineItem,
    mode: PhaseTimelineDisplayMode,
    todayIso: string,
    options?: {
        timeZone?: string | null;
        formatDate?: (value: string | null | undefined) => string;
    },
): PhaseDateLineSummary {
    const timeZone = options?.timeZone;
    const formatDate =
        options?.formatDate ??
        ((value: string | null | undefined): string =>
            value ? String(value) : '—');

    const variance = summarizePhaseVariance(phase, timeZone);
    const showPlanned = mode !== 'actual_only';
    const showActual = mode !== 'planned_only';
    const showVariance = mode === 'plan_vs_actual';
    const hasPlanned = phaseHasPlannedDates(phase);

    const plannedStartLabel = formatDateLabel(
        phase.planned_start_at,
        'Not recorded',
        formatDate,
    );
    const plannedEndLabel = phase.planned_end_at
        ? formatDate(phase.planned_end_at)
        : hasPlanned
          ? '—'
          : null;

    const actualStartLabel = formatDateLabel(
        phase.actual_start_at,
        variance.isNotStarted ? 'Not started' : 'Not recorded',
        formatDate,
    );
    const actualEndLabel = variance.hasConfirmedActualEnd
        ? formatDate(phase.actual_end_at)
        : variance.isInProgress
          ? 'In progress'
          : null;

    const elapsedDays = variance.isInProgress
        ? elapsedWholeCalendarDays(phase.actual_start_at, todayIso, timeZone)
        : null;
    const untilStart =
        showPlanned && variance.isNotStarted && phase.planned_start_at
            ? daysUntilPlannedStart(phase.planned_start_at, todayIso, timeZone)
            : null;
    // Planned overdue is comparison context — never in Actual Only.
    const pastPlannedEnd =
        showPlanned && variance.isInProgress && phase.planned_end_at
            ? daysPastPlannedEnd(phase.planned_end_at, todayIso, timeZone)
            : null;

    const durationParts: string[] = [];

    if (showPlanned && variance.plannedDurationDays !== null) {
        durationParts.push(
            `Planned ${formatDayCount(variance.plannedDurationDays)}`,
        );
    }

    if (showActual && variance.hasConfirmedActualEnd) {
        durationParts.push(
            `${formatDayCount(variance.actualDurationDays)} completed`,
        );
    } else if (showActual && variance.isInProgress) {
        const elapsed = formatElapsedDayPhrase(elapsedDays);

        if (elapsed) {
            durationParts.push(elapsed);
        }
    }

    if (untilStart !== null) {
        durationParts.push(`${formatDayCount(untilStart)} until planned start`);
    }

    if (pastPlannedEnd !== null) {
        durationParts.push(
            `${formatDayCount(pastPlannedEnd)} past planned end`,
        );
    }

    const plannedDurationLabel = formatDayCount(variance.plannedDurationDays);
    const actualDurationLabel = variance.hasConfirmedActualEnd
        ? formatDayCount(variance.actualDurationDays)
        : variance.isInProgress
          ? 'In progress'
          : '—';

    return {
        showPlanned,
        showActual,
        showVariance,
        hasPlanned,
        plannedStartLabel,
        plannedEndLabel,
        actualStartLabel,
        actualEndLabel,
        durationParts,
        plannedDurationLabel,
        actualDurationLabel,
    };
}

/** True when any duration/date phrase still mentions planned overdue/windows. */
export function durationPartsLeakPlannedOverdue(parts: string[]): boolean {
    return parts.some(
        (part) =>
            /past planned end/i.test(part) ||
            /until planned start/i.test(part) ||
            /^Planned\b/.test(part),
    );
}
