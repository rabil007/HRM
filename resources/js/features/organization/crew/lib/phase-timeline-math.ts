import {
    formatUtcIsoDate,
    inclusivePeriodPositionStyle,
    parseIsoToUtcMs,
} from '../../crew-planning/lib/planning-gantt-math.ts';
import type { PhaseTimelineItem } from '../types.ts';

export type PhaseTimelineDisplayMode =
    | 'plan_vs_actual'
    | 'actual_only'
    | 'planned_only';

export const PHASE_TIMELINE_DISPLAY_MODES: Array<{
    value: PhaseTimelineDisplayMode;
    label: string;
}> = [
    { value: 'plan_vs_actual', label: 'Plan vs Actual' },
    { value: 'actual_only', label: 'Actual Only' },
    { value: 'planned_only', label: 'Planned Only' },
];

const MS_PER_DAY = 86_400_000;

/** Inclusive calendar-day duration. Same-day start/end counts as 1 day. */
export function inclusiveDurationDays(
    start: string | null | undefined,
    end: string | null | undefined,
): number | null {
    if (!start || !end) {
        return null;
    }

    const delta = Math.round(
        (parseIsoToUtcMs(end) - parseIsoToUtcMs(start)) / MS_PER_DAY,
    );

    if (delta < 0) {
        return null;
    }

    return delta + 1;
}

/** Whole-day signed delta: positive means `actual` is after `planned`. */
export function signedDayDelta(
    planned: string | null | undefined,
    actual: string | null | undefined,
): number | null {
    if (!planned || !actual) {
        return null;
    }

    return Math.round(
        (parseIsoToUtcMs(actual) - parseIsoToUtcMs(planned)) / MS_PER_DAY,
    );
}

export function collectTimelineDates(
    phases: PhaseTimelineItem[],
    mode: PhaseTimelineDisplayMode,
): string[] {
    const dates: string[] = [];

    for (const phase of phases) {
        if (mode !== 'actual_only') {
            if (phase.planned_start_at) {
                dates.push(phase.planned_start_at);
            }

            if (phase.planned_end_at) {
                dates.push(phase.planned_end_at);
            }
        }

        if (mode !== 'planned_only') {
            if (phase.actual_start_at) {
                dates.push(phase.actual_start_at);
            }

            if (phase.actual_end_at) {
                dates.push(phase.actual_end_at);
            }
        }
    }

    return dates;
}

export function resolveSharedTimelineRange(
    dates: string[],
    todayIso?: string | null,
): { from: string; to: string } | null {
    if (dates.length === 0) {
        return null;
    }

    const sorted = [...dates].sort();
    let from = sorted[0]!;
    let to = sorted[sorted.length - 1]!;

    if (todayIso && todayIso < from) {
        from = todayIso;
    }

    if (todayIso && todayIso > to) {
        to = todayIso;
    }

    // Ensure a usable visual span for single-day timelines.
    if (from === to) {
        const fromMs = parseIsoToUtcMs(from);
        from = formatUtcIsoDate(fromMs - MS_PER_DAY);
        to = formatUtcIsoDate(fromMs + MS_PER_DAY);
    }

    return { from, to };
}

export function phaseBarStyle(
    start: string | null | undefined,
    end: string | null | undefined,
    rangeFrom: string,
    rangeTo: string,
    options?: { openEndedVisualEnd?: string | null },
): { left: string; width: string } | { display: 'none' } {
    if (!start) {
        return { display: 'none' };
    }

    const visualEnd = end ?? options?.openEndedVisualEnd ?? start;

    return inclusivePeriodPositionStyle(
        start,
        visualEnd,
        new Date(parseIsoToUtcMs(rangeFrom)),
        new Date(parseIsoToUtcMs(rangeTo)),
    );
}

export function formatDayCount(days: number | null): string {
    if (days === null) {
        return '—';
    }

    return `${days} day${days === 1 ? '' : 's'}`;
}

export function formatSignedDayPhrase(
    days: number | null,
    earlyLabel: string,
    lateLabel: string,
    onTimeLabel = 'on time',
): string | null {
    if (days === null) {
        return null;
    }

    if (days === 0) {
        return onTimeLabel;
    }

    const abs = Math.abs(days);
    const unit = abs === 1 ? 'day' : 'days';

    if (days < 0) {
        return `${abs} ${unit} ${earlyLabel}`;
    }

    return `${abs} ${unit} ${lateLabel}`;
}
