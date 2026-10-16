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
import {
    shouldHideP0MobilisationActivationLines,
    shouldShowP0ExpectedArrival,
} from './phase-timeline-p0-expected-arrival.ts';
import type { P0ExpectedArrivalContext } from './phase-timeline-p0-expected-arrival.ts';
import { summarizePhaseVariance } from './phase-timeline-variance.ts';

export type PhaseDateLineSummary = {
    showPlanned: boolean;
    showActual: boolean;
    showVariance: boolean;
    hasPlanned: boolean;
    showExpectedArrival: boolean;
    expectedArrivalLabel: string | null;
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
        p0ExpectedArrivalContext?: P0ExpectedArrivalContext;
    },
): PhaseDateLineSummary {
    const timeZone = options?.timeZone;
    const formatDate =
        options?.formatDate ??
        ((value: string | null | undefined): string =>
            value ? String(value) : '—');

    const p0Context = options?.p0ExpectedArrivalContext;
    const hideP0MobilisationActivation =
        shouldHideP0MobilisationActivationLines(phase, p0Context);

    const variance = summarizePhaseVariance(phase, timeZone, {
        p0ExpectedArrivalContext: p0Context,
    });
    const showPlanned = mode !== 'actual_only';
    let showActual = mode !== 'planned_only';
    const showVariance = mode === 'plan_vs_actual';

    if (hideP0MobilisationActivation) {
        showActual = false;
    }

    const hasPlanned = phaseHasPlannedDates(phase);
    const showExpectedArrival =
        p0Context !== undefined &&
        shouldShowP0ExpectedArrival(phase, mode, p0Context) &&
        !hasPlanned;
    const expectedArrivalLabel = showExpectedArrival
        ? formatDateLabel(p0Context!.plannedArrivalAt, '—', formatDate)
        : null;

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

    if (!hideP0MobilisationActivation) {
        if (showPlanned && variance.plannedDurationDays !== null) {
            durationParts.push(
                `Planned ${formatDayCount(variance.plannedDurationDays)}`,
            );
        }

        if (showActual && variance.hasConfirmedActualEnd) {
            const servicePhrase = payableServicePhrase(phase);

            if (servicePhrase) {
                durationParts.push(servicePhrase);

                if (
                    variance.actualDurationDays !== null &&
                    phase.allocated_service_days !== variance.actualDurationDays
                ) {
                    durationParts.push(
                        `Movement span: ${formatDayCount(variance.actualDurationDays)}`,
                    );
                }
            } else {
                durationParts.push(
                    `${formatDayCount(variance.actualDurationDays)} completed`,
                );
            }
        } else if (showActual && variance.isInProgress) {
            const elapsed = formatElapsedDayPhrase(elapsedDays);

            if (elapsed) {
                durationParts.push(elapsed);
            }
        }

        if (untilStart !== null) {
            durationParts.push(
                `${formatDayCount(untilStart)} until planned start`,
            );
        }

        if (pastPlannedEnd !== null) {
            durationParts.push(
                `${formatDayCount(pastPlannedEnd)} past planned end`,
            );
        }
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
        showExpectedArrival,
        expectedArrivalLabel,
        plannedStartLabel,
        plannedEndLabel,
        actualStartLabel,
        actualEndLabel,
        durationParts,
        plannedDurationLabel,
        actualDurationLabel,
    };
}

export type OperationalPhaseLine = {
    label: string;
    value: string;
    planned: boolean;
};

export type OperationalPhaseContext = {
    isCurrent: boolean;
    todayIso: string;
    timeZone?: string | null;
    assignmentStatus?: string | null;
    plannedArrivalAt?: string | null;
    plannedJoinAt?: string | null;
    plannedSignoffAt?: string | null;
    plannedTravelAt?: string | null;
    formatDate?: (value: string | null | undefined) => string;
};

/**
 * Operational status for one timeline row.
 * A null actual end never makes a phase Active. Draft and planned rows stay
 * Not Started or Planned until the phase itself is active.
 */
function operationalPhaseStatus(
    phase: PhaseTimelineItem,
    assignmentStatus?: string | null,
): string | null {
    if (phase.status === 'completed' || phase.actual_end_at) {
        return null;
    }

    if (phase.status === 'cancelled' || assignmentStatus === 'cancelled') {
        return 'Cancelled';
    }

    if (assignmentStatus === 'draft') {
        return 'Not Started';
    }

    if (assignmentStatus === 'completed') {
        return 'Completed';
    }

    if (phase.status === 'active') {
        return 'Active';
    }

    return 'Planned';
}

function displayDate(
    value: string | null | undefined,
    formatDate: (value: string | null | undefined) => string,
): string | null {
    if (!value) {
        return null;
    }

    const formatted = formatDate(value);

    return formatted === '—' ? null : formatted;
}

function phaseStartLabel(code: string): string {
    switch (code) {
        case 'p1a':
        case 'p1':
        case 'p2a':
            return 'Arrived';
        case 'p1b':
        case 'p2b':
            return 'Training Started';
        case 'p2':
            return 'Joined Vessel';
        case 'p3':
        case 'p5':
            return 'Disembarked';
        case 'p4':
        case 'p6':
            return 'Returned Home';
        default:
            return 'Started';
    }
}

/**
 * One operational timeline: recorded actual movements, plus the relevant
 * upcoming planned milestone while the phase is still active.
 * Planned dates are never labeled as actual movements.
 */
export function buildOperationalPhaseLines(
    phase: PhaseTimelineItem,
    context: OperationalPhaseContext,
): OperationalPhaseLine[] {
    const formatDate =
        context.formatDate ??
        ((value: string | null | undefined): string =>
            value ? String(value) : '—');
    const completed =
        phase.status === 'completed' || Boolean(phase.actual_end_at);
    const operationalStatus = operationalPhaseStatus(
        phase,
        context.assignmentStatus,
    );
    const operationallyActive = operationalStatus === 'Active';
    const lines: OperationalPhaseLine[] = [];
    const actualStart = displayDate(phase.actual_start_at, formatDate);
    const actualEnd = displayDate(phase.actual_end_at, formatDate);

    if (completed && actualStart) {
        lines.push({
            label: 'Actual Movement',
            value: actualEnd ? `${actualStart} → ${actualEnd}` : actualStart,
            planned: false,
        });
    }

    if (
        !completed &&
        operationalStatus !== 'Cancelled' &&
        phase.phase_code === 'p0' &&
        context.isCurrent
    ) {
        const expected = displayDate(context.plannedArrivalAt, formatDate);

        lines.push(
            expected
                ? {
                      label: 'Expected Arrival',
                      value: expected,
                      planned: true,
                  }
                : operationallyActive && actualStart
                  ? {
                        label: 'P0 Started',
                        value: actualStart,
                        planned: false,
                    }
                  : {
                        label: 'Expected Arrival',
                        value: 'Arrival Not Planned',
                        planned: true,
                    },
        );
    } else if (
        operationallyActive &&
        actualStart &&
        phase.phase_code !== 'p0'
    ) {
        lines.push({
            label: phaseStartLabel(phase.phase_code),
            value: actualStart,
            planned: false,
        });
    }

    if (
        !completed &&
        operationalStatus !== 'Cancelled' &&
        (operationallyActive || operationalStatus === 'Planned') &&
        phase.phase_code !== 'p0'
    ) {
        lines.push(activeMilestone(phase, context, formatDate));
    }

    if (operationalStatus) {
        lines.push({
            label: 'Status',
            value: operationalStatus,
            planned: false,
        });
    }

    if (!completed) {
        if (
            operationallyActive &&
            phase.phase_code !== 'p0' &&
            phase.actual_start_at
        ) {
            const elapsed = formatElapsedDayPhrase(
                elapsedWholeCalendarDays(
                    phase.actual_start_at,
                    context.todayIso,
                    context.timeZone,
                ),
            );

            if (elapsed) {
                lines.push({
                    label: 'Elapsed',
                    value: elapsed,
                    planned: false,
                });
            }
        }
    } else {
        const service = payableServicePhrase(phase);

        if (service) {
            lines.push({
                label: 'Service',
                value: service,
                planned: false,
            });
        } else if (phase.actual_start_at && phase.actual_end_at) {
            const variance = summarizePhaseVariance(phase, context.timeZone);
            const span = formatDayCount(variance.actualDurationDays);

            if (variance.actualDurationDays !== null) {
                lines.push({
                    label: 'Duration',
                    value: `${span} calendar span`,
                    planned: false,
                });
            }
        }
    }

    return lines;
}

function activeMilestone(
    phase: PhaseTimelineItem,
    context: OperationalPhaseContext,
    formatDate: (value: string | null | undefined) => string,
): OperationalPhaseLine {
    const plannedEnd = displayDate(phase.planned_end_at, formatDate);

    switch (phase.phase_code) {
        case 'p1a':
        case 'p1':
        case 'p2a': {
            const join = displayDate(context.plannedJoinAt, formatDate);

            return join
                ? {
                      label: 'Expected Vessel Join',
                      value: join,
                      planned: true,
                  }
                : {
                      label: 'Expected Vessel Join',
                      value: 'Join Not Planned',
                      planned: true,
                  };
        }
        case 'p1b':
        case 'p2b':
            return plannedEnd
                ? {
                      label: 'Expected Training Completion',
                      value: plannedEnd,
                      planned: true,
                  }
                : {
                      label: 'Expected Training Completion',
                      value: 'Training Completion Not Planned',
                      planned: true,
                  };
        case 'p2': {
            const signoff =
                displayDate(context.plannedSignoffAt, formatDate) ?? plannedEnd;

            return signoff
                ? {
                      label: 'Planned Sign-Off',
                      value: signoff,
                      planned: true,
                  }
                : {
                      label: 'Planned Sign-Off',
                      value: 'Sign-Off Not Planned',
                      planned: true,
                  };
        }
        case 'p3':
        case 'p5': {
            const travel = displayDate(context.plannedTravelAt, formatDate);

            return travel
                ? {
                      label: 'Planned Travel Home',
                      value: travel,
                      planned: true,
                  }
                : {
                      label: 'Planned Travel Home',
                      value: 'Travel Home Not Planned',
                      planned: true,
                  };
        }
        case 'p4':
        case 'p6':
            return {
                label: 'Home / Redeployment',
                value: 'Awaiting closure',
                planned: false,
            };
        default:
            return plannedEnd
                ? {
                      label: 'Planned End',
                      value: plannedEnd,
                      planned: true,
                  }
                : {
                      label: 'Planned End',
                      value: 'Not Planned',
                      planned: true,
                  };
    }
}

function payableServicePhrase(phase: PhaseTimelineItem): string | null {
    if (
        phase.allocated_service_days === null ||
        phase.allocated_service_days === undefined
    ) {
        return null;
    }

    const count = formatDayCount(phase.allocated_service_days);

    switch (phase.phase_code) {
        case 'p1a':
        case 'p1b':
            return `Standby service: ${count}`;
        case 'p3':
            return `Sign-off standby: ${count}`;
        case 'p2':
            return `Vessel service: ${count}`;
        default:
            return null;
    }
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
