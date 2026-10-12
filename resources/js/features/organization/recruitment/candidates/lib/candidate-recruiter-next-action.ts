import type { CandidateDetail, CandidateIndexRow } from '../types';
import type { PrimaryAction } from './candidate-primary-action.ts';
import {
    blockedNextStepHint,
    resolvePrimaryAction,
} from './candidate-primary-action.ts';

export type RecruiterOtherAction = {
    key: string;
    label: string;
    kind: 'post' | 'destination' | 'link';
    url?: string;
    href?: string;
    destinationStage?: string;
    destinationKind?: string;
};

export type RecruiterNextActionModel = {
    currentState: string;
    recommended: PrimaryAction | null;
    why: string | null;
    otherActions: RecruiterOtherAction[];
    attention: string[];
};

export function resolveCurrentStateLabel(candidate: CandidateIndexRow): string {
    if (candidate.stage === 'rejected') {
        return 'Rejected';
    }

    if (candidate.stage === 'joined') {
        if (candidate.conversion_status === 'converted') {
            return 'Joined — Converted';
        }

        if (candidate.conversion_status === 'pending') {
            return 'Joined — Awaiting Conversion';
        }

        return 'Joined';
    }

    if (candidate.stage === 'interview') {
        if (candidate.interview_outcome === 'selected') {
            return 'Interview — Selected';
        }

        if (candidate.interview_outcome === 'not_selected') {
            return 'Interview — Not Selected';
        }

        return 'Interview — Pending Decision';
    }

    if (candidate.stage === 'offer_jol') {
        if (candidate.offer_status === 'draft') {
            return 'Offer/JOL — Draft';
        }

        if (candidate.offer_status === 'sent') {
            return 'Offer/JOL — Sent';
        }

        if (candidate.offer_status === 'accepted') {
            if (candidate.joining_readiness_status === 'ready') {
                return 'Offer/JOL — Accepted, Ready';
            }

            return 'Offer/JOL — Accepted, Readiness Pending';
        }

        if (candidate.offer_status === 'rejected') {
            return 'Offer/JOL — Offer Rejected';
        }

        return 'Offer/JOL';
    }

    return candidate.stage_label;
}

export function resolveWhyThisAction(
    candidate: CandidateIndexRow,
    recommended: PrimaryAction | null,
): string | null {
    if (!recommended) {
        return blockedNextStepHint(candidate);
    }

    switch (recommended.label) {
        case 'Move to Screening':
            return 'Advance the candidate after an initial application review.';
        case 'Move to Interview':
            return 'Schedule or capture interview details for the next evaluation step.';
        case 'Mark Selected':
            return 'Record a successful interview outcome before preparing an offer.';
        case 'Prepare Offer':
            return 'Create the Offer/JOL with salary and joining details while the candidate is selected.';
        case 'Complete Draft Offer':
            return 'Finish the draft Offer/JOL before marking it as sent.';
        case 'Review Sent Offer':
            return 'Record acceptance or rejection after the offer was sent.';
        case 'Update Readiness':
            return 'Confirm joining readiness or record blockers before Confirm Joined.';
        case 'Confirm Joined':
            return 'Record the actual joining date once the candidate is ready to join.';
        case 'Convert to Employee':
            return 'Create the employee record for this joined candidate.';
        default:
            return `Recommended next step: ${recommended.label}.`;
    }
}

export function resolveAttentionItems(
    candidate: CandidateIndexRow | CandidateDetail,
): string[] {
    const items: string[] = [];

    if (!candidate.parents_valid) {
        items.push('Requirement or position line is closed or missing.');
    }

    if (!candidate.can_move && candidate.stage !== 'joined') {
        items.push('Action unavailable due to permission or ownership.');
    }

    if (
        candidate.stage === 'interview' &&
        candidate.interview_outcome === null &&
        !candidate.interview_scheduled_at
    ) {
        items.push('Interview schedule has not been set yet.');
    }

    if (candidate.stage === 'offer_jol' && candidate.offer_status === 'draft') {
        items.push('Offer details are still in Draft.');
    }

    if (candidate.stage === 'offer_jol' && candidate.offer_status === 'sent') {
        items.push('Offer has not been accepted yet.');
    }

    if (
        candidate.stage === 'offer_jol' &&
        candidate.offer_status === 'accepted' &&
        candidate.joining_readiness_status !== 'ready'
    ) {
        items.push('Joining readiness is still Pending.');
    }

    if (candidate.joining_blocker_notes?.trim()) {
        items.push('Joining blocker recorded.');
    }

    if (!candidate.has_cv) {
        items.push('Candidate has no CV (informational).');
    }

    const detail = candidate as CandidateDetail;

    if (
        detail.interview &&
        candidate.stage === 'interview' &&
        candidate.interview_outcome === 'selected' &&
        detail.interview.edit_blocked_reason
    ) {
        items.push(detail.interview.edit_blocked_reason);
    }

    return items;
}

export function resolveOtherActions(
    candidate: CandidateIndexRow,
    recommended: PrimaryAction | null,
): RecruiterOtherAction[] {
    const actions: RecruiterOtherAction[] = [];
    const recommendedLabel = recommended?.label ?? null;

    for (const destination of candidate.move_destinations ?? []) {
        if (
            destination.kind === 'blocked' ||
            destination.kind === 'select_required'
        ) {
            continue;
        }

        if (
            recommended?.kind === 'destination' &&
            recommended.destination.stage === destination.stage &&
            recommended.destination.kind === destination.kind
        ) {
            continue;
        }

        if (
            recommendedLabel &&
            destination.label === recommendedLabel &&
            recommended?.kind === 'destination'
        ) {
            continue;
        }

        actions.push({
            key: `${destination.kind}-${destination.stage}`,
            label: destination.label,
            kind: 'destination',
            destinationStage: destination.stage,
            destinationKind: destination.kind,
        });
    }

    if (candidate.can_undo_selected) {
        actions.push({
            key: 'undo-selected',
            label: 'Undo Selected',
            kind: 'post',
            url: `/organization/recruitment/candidates/${candidate.id}/undo-select`,
        });
    }

    if (candidate.can_reject) {
        actions.push({
            key: 'reject',
            label: 'Reject',
            kind: 'post',
            url: `/organization/recruitment/candidates/${candidate.id}/reject`,
        });
    }

    if (candidate.can_reopen) {
        actions.push({
            key: 'reopen',
            label: 'Reopen',
            kind: 'post',
            url: `/organization/recruitment/candidates/${candidate.id}/reopen`,
        });
    }

    if (
        candidate.can_update_readiness &&
        recommendedLabel !== 'Update Readiness'
    ) {
        actions.push({
            key: 'update-readiness',
            label: 'Update Readiness',
            kind: 'link',
            href: `/organization/recruitment/candidates/${candidate.id}#joining`,
        });
    }

    if (
        candidate.conversion_status === 'converted' &&
        candidate.linked_employee?.can_view &&
        candidate.linked_employee.id
    ) {
        actions.push({
            key: 'view-employee',
            label: 'View Employee',
            kind: 'link',
            href: `/organization/employees/${candidate.linked_employee.id}`,
        });
    } else if (
        candidate.stage === 'joined' &&
        !candidate.can_convert &&
        recommendedLabel !== 'Convert to Employee'
    ) {
        // Link Existing Employee is only shown when convert is not the primary path.
    }

    return actions;
}

export function buildRecruiterNextAction(
    candidate: CandidateIndexRow | CandidateDetail,
): RecruiterNextActionModel {
    const recommended = resolvePrimaryAction(candidate);

    return {
        currentState: resolveCurrentStateLabel(candidate),
        recommended,
        why: resolveWhyThisAction(candidate, recommended),
        otherActions: resolveOtherActions(candidate, recommended),
        attention: resolveAttentionItems(candidate),
    };
}
