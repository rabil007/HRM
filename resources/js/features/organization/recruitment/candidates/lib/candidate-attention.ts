import type { CandidateIndexRow } from '../types';

/** Stall threshold for subtle visual cue only (not a domain status). */
export const STALL_DAYS_THRESHOLD = 7;

/**
 * Reliable “needs attention” signals from existing board fields:
 * joining urgency, recorded blockers, or joined-but-not-converted.
 */
export function candidateNeedsAttention(candidate: CandidateIndexRow): boolean {
    if (
        candidate.joining_schedule_urgency === 'overdue' ||
        candidate.joining_schedule_urgency === 'today'
    ) {
        return true;
    }

    if (candidate.joining_blocker_notes?.trim()) {
        return true;
    }

    if (candidate.conversion_status === 'pending') {
        return true;
    }

    return false;
}

export function candidateIsStalled(candidate: CandidateIndexRow): boolean {
    return (
        typeof candidate.days_in_stage === 'number' &&
        candidate.days_in_stage >= STALL_DAYS_THRESHOLD &&
        candidate.stage !== 'joined' &&
        candidate.stage !== 'rejected'
    );
}
