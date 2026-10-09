import { toCompanyDateLocal } from '../../../../lib/company-timezone.ts';
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

/**
 * Normalize any supported date/datetime representation to a company-local
 * calendar day (`YYYY-MM-DD`). Returns null for missing/invalid values.
 */
export function normalizeCalendarDate(
    value: string | null | undefined,
    timeZone?: string | null,
): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    const trimmed = String(value).trim();

    if (trimmed === '') {
        return null;
    }

    const normalized = toCompanyDateLocal(trimmed, timeZone);

    return normalized === '' ? null : normalized;
}

/** True when both values resolve to the same company-local calendar day. */
export function calendarDatesEqual(
    left: string | null | undefined,
    right: string | null | undefined,
    timeZone?: string | null,
): boolean {
    const a = normalizeCalendarDate(left, timeZone);
    const b = normalizeCalendarDate(right, timeZone);

    if (a === null || b === null) {
        return false;
    }

    return a === b;
}

/**
 * True when both values are present and resolve to different calendar days.
 * Missing/invalid pairs never count as variance.
 */
export function calendarDatesDiffer(
    left: string | null | undefined,
    right: string | null | undefined,
    timeZone?: string | null,
): boolean {
    const a = normalizeCalendarDate(left, timeZone);
    const b = normalizeCalendarDate(right, timeZone);

    if (a === null || b === null) {
        return false;
    }

    return a !== b;
}

export function phaseHasPlannedDates(phase: PhaseTimelineItem): boolean {
    return Boolean(phase.planned_start_at || phase.planned_end_at);
}

export function phasesHavePlannedDates(phases: PhaseTimelineItem[]): boolean {
    return phases.some((phase) => phaseHasPlannedDates(phase));
}

/**
 * Include today in the shared scale only when an open-ended actual phase needs
 * a visual end. Historical completed assignments must not stretch to today.
 */
export function shouldIncludeTodayInTimelineRange(
    phases: PhaseTimelineItem[],
    mode: PhaseTimelineDisplayMode,
): boolean {
    if (mode === 'planned_only') {
        return false;
    }

    return phases.some(
        (phase) =>
            Boolean(phase.actual_start_at) &&
            !phase.actual_end_at &&
            (phase.status === 'active' || phase.status === 'planned'),
    );
}

/** Inclusive calendar-day duration. Same-day start/end counts as 1 day. */
export function inclusiveDurationDays(
    start: string | null | undefined,
    end: string | null | undefined,
    timeZone?: string | null,
): number | null {
    const startDate = normalizeCalendarDate(start, timeZone);
    const endDate = normalizeCalendarDate(end, timeZone);

    if (!startDate || !endDate) {
        return null;
    }

    const delta = Math.round(
        (parseIsoToUtcMs(endDate) - parseIsoToUtcMs(startDate)) / MS_PER_DAY,
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
    timeZone?: string | null,
): number | null {
    const plannedDate = normalizeCalendarDate(planned, timeZone);
    const actualDate = normalizeCalendarDate(actual, timeZone);

    if (!plannedDate || !actualDate) {
        return null;
    }

    return Math.round(
        (parseIsoToUtcMs(actualDate) - parseIsoToUtcMs(plannedDate)) /
            MS_PER_DAY,
    );
}

export function collectTimelineDates(
    phases: PhaseTimelineItem[],
    mode: PhaseTimelineDisplayMode,
    timeZone?: string | null,
): string[] {
    const dates: string[] = [];

    const push = (value: string | null | undefined): void => {
        const normalized = normalizeCalendarDate(value, timeZone);

        if (normalized) {
            dates.push(normalized);
        }
    };

    for (const phase of phases) {
        if (mode !== 'actual_only') {
            push(phase.planned_start_at);
            push(phase.planned_end_at);
        }

        if (mode !== 'planned_only') {
            push(phase.actual_start_at);
            push(phase.actual_end_at);
        }
    }

    return dates;
}

export function resolveSharedTimelineRange(
    dates: string[],
    options?: {
        todayIso?: string | null;
        includeToday?: boolean;
        timeZone?: string | null;
    },
): { from: string; to: string } | null {
    const normalizedDates = dates
        .map((date) => normalizeCalendarDate(date, options?.timeZone))
        .filter((date): date is string => date !== null);

    if (normalizedDates.length === 0) {
        return null;
    }

    const sorted = [...normalizedDates].sort();
    let from = sorted[0]!;
    let to = sorted[sorted.length - 1]!;

    const todayIso =
        options?.includeToday === true
            ? normalizeCalendarDate(options.todayIso, options.timeZone)
            : null;

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
    options?: {
        openEndedVisualEnd?: string | null;
        timeZone?: string | null;
    },
): { left: string; width: string } | { display: 'none' } {
    const startDate = normalizeCalendarDate(start, options?.timeZone);

    if (!startDate) {
        return { display: 'none' };
    }

    const endDate =
        normalizeCalendarDate(end, options?.timeZone) ??
        normalizeCalendarDate(options?.openEndedVisualEnd, options?.timeZone) ??
        startDate;

    return inclusivePeriodPositionStyle(
        startDate,
        endDate,
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

/**
 * Whole calendar days elapsed from an actual start up to company-local today.
 * Same convention as backend `wholeDaysSince`: start day → today is exclusive
 * of inventing a confirmed end (started today = 0 complete days).
 */
export function elapsedWholeCalendarDays(
    start: string | null | undefined,
    todayIso: string,
    timeZone?: string | null,
): number | null {
    const startDate = normalizeCalendarDate(start, timeZone);
    const todayDate = normalizeCalendarDate(todayIso, timeZone);

    if (!startDate || !todayDate) {
        return null;
    }

    const delta = Math.round(
        (parseIsoToUtcMs(todayDate) - parseIsoToUtcMs(startDate)) / MS_PER_DAY,
    );

    if (delta < 0) {
        return null;
    }

    return delta;
}

/**
 * Days until a future planned start (positive), or null when missing / not future.
 * Does not invent overdue labels for informational planned starts that have passed
 * without an actual — callers decide presentation.
 */
export function daysUntilPlannedStart(
    plannedStart: string | null | undefined,
    todayIso: string,
    timeZone?: string | null,
): number | null {
    const plannedDate = normalizeCalendarDate(plannedStart, timeZone);
    const todayDate = normalizeCalendarDate(todayIso, timeZone);

    if (!plannedDate || !todayDate) {
        return null;
    }

    const delta = Math.round(
        (parseIsoToUtcMs(plannedDate) - parseIsoToUtcMs(todayDate)) /
            MS_PER_DAY,
    );

    return delta > 0 ? delta : null;
}

/**
 * Days past a planned end while a phase is still open. Null when there is no
 * planned end, or today is still on/before that deadline.
 */
export function daysPastPlannedEnd(
    plannedEnd: string | null | undefined,
    todayIso: string,
    timeZone?: string | null,
): number | null {
    const plannedDate = normalizeCalendarDate(plannedEnd, timeZone);
    const todayDate = normalizeCalendarDate(todayIso, timeZone);

    if (!plannedDate || !todayDate) {
        return null;
    }

    const delta = Math.round(
        (parseIsoToUtcMs(todayDate) - parseIsoToUtcMs(plannedDate)) /
            MS_PER_DAY,
    );

    return delta > 0 ? delta : null;
}

export function formatElapsedDayPhrase(days: number | null): string | null {
    if (days === null) {
        return null;
    }

    if (days === 0) {
        return 'Started today';
    }

    return `${formatDayCount(days)} elapsed`;
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
