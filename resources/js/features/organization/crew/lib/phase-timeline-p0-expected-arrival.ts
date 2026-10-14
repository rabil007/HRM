import type { PhaseTimelineItem } from '../types.ts';
import type { PhaseTimelineDisplayMode } from './phase-timeline-math.ts';
import { phaseHasPlannedDates } from './phase-timeline-math.ts';

export type P0ExpectedArrivalContext = {
    assignmentStatus: string;
    isCurrentPhase: boolean;
    plannedArrivalAt: string | null | undefined;
};

/** Assignment-level forecast for the active current P0 row (not phase planned start). */
export function shouldShowP0ExpectedArrival(
    phase: PhaseTimelineItem,
    mode: PhaseTimelineDisplayMode,
    context: P0ExpectedArrivalContext,
): boolean {
    if (phase.phase_code !== 'p0') {
        return false;
    }

    if (mode === 'actual_only') {
        return false;
    }

    if (!context.isCurrentPhase) {
        return false;
    }

    if (context.assignmentStatus !== 'active') {
        return false;
    }

    const arrival = context.plannedArrivalAt;

    return (
        arrival !== null &&
        arrival !== undefined &&
        String(arrival).trim() !== ''
    );
}

/** Avoid "variance unavailable (no planned dates)" when forecast is shown on current P0. */
export function shouldUseNeutralP0InProgressHeadline(
    phase: PhaseTimelineItem,
    context: P0ExpectedArrivalContext,
): boolean {
    if (!shouldShowP0ExpectedArrival(phase, 'plan_vs_actual', context)) {
        return false;
    }

    if (phaseHasPlannedDates(phase)) {
        return false;
    }

    return Boolean(phase.actual_start_at) && !phase.actual_end_at;
}

/** Active current P0: phase activation is assignment start, not crew arrival. */
export function shouldHideP0MobilisationActivationLines(
    phase: PhaseTimelineItem,
    context: P0ExpectedArrivalContext | undefined,
): boolean {
    if (phase.phase_code !== 'p0' || context === undefined) {
        return false;
    }

    if (!context.isCurrentPhase || context.assignmentStatus !== 'active') {
        return false;
    }

    return phase.status === 'active' || phase.status === 'planned';
}

export function assignmentShowsP0ExpectedArrivalInPlannedOnly(assignment: {
    status: string;
    planned_arrival_at?: string | null;
    current_phase?: { id?: number; code?: string | null } | null;
    phase_timeline: PhaseTimelineItem[];
}): boolean {
    const current = assignment.current_phase;

    if (assignment.status !== 'active' || current?.code !== 'p0') {
        return false;
    }

    const arrival = assignment.planned_arrival_at;

    if (
        arrival === null ||
        arrival === undefined ||
        String(arrival).trim() === ''
    ) {
        return false;
    }

    const currentPhase = assignment.phase_timeline.find(
        (row) => row.id === current.id,
    );

    return currentPhase?.phase_code === 'p0';
}
