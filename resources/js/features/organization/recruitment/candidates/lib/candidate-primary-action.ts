import type { CandidateIndexRow, CandidateMoveDestination } from '../types';
import { candidateDetailSectionHref } from './candidate-section-hash.ts';

export type PrimaryAction =
    | {
          kind: 'post';
          label: string;
          url: string;
      }
    | {
          kind: 'link';
          label: string;
          href: string;
      }
    | {
          kind: 'destination';
          label: string;
          destination: CandidateMoveDestination;
      };

/**
 * Resolve the single primary CTA for a candidate card / detail header.
 * Order follows the recruitment workflow so Confirm Joined wins over Update Readiness.
 */
export function resolvePrimaryAction(
    candidate: CandidateIndexRow,
): PrimaryAction | null {
    if (candidate.can_move_forward) {
        const forward = (candidate.move_destinations ?? []).find(
            (destination) => destination.kind === 'forward',
        );

        if (forward) {
            return {
                kind: 'destination',
                label: forward.label,
                destination: forward,
            };
        }
    }

    if (candidate.can_select) {
        return {
            kind: 'post',
            label: 'Mark Selected',
            url: `/organization/recruitment/candidates/${candidate.id}/select`,
        };
    }

    if (candidate.can_prepare_offer) {
        return {
            kind: 'link',
            label: 'Prepare Offer',
            href: candidateDetailSectionHref(candidate.id, 'offer-jol'),
        };
    }

    if (candidate.stage === 'offer_jol') {
        if (candidate.offer_status === 'draft') {
            return {
                kind: 'link',
                label: 'Complete Draft Offer',
                href: candidateDetailSectionHref(candidate.id, 'offer-jol'),
            };
        }

        if (candidate.offer_status === 'sent') {
            return {
                kind: 'link',
                label: 'Review Sent Offer',
                href: candidateDetailSectionHref(candidate.id, 'offer-jol'),
            };
        }

        if (candidate.can_confirm_joined) {
            const confirm = (candidate.move_destinations ?? []).find(
                (destination) => destination.kind === 'confirm_joined',
            );

            if (confirm) {
                return {
                    kind: 'destination',
                    label: 'Confirm Joined',
                    destination: confirm,
                };
            }

            return {
                kind: 'link',
                label: 'Confirm Joined',
                href: candidateDetailSectionHref(candidate.id, 'joining'),
            };
        }

        if (candidate.can_update_readiness) {
            return {
                kind: 'link',
                label: 'Update Readiness',
                href: candidateDetailSectionHref(candidate.id, 'joining'),
            };
        }
    }

    if (candidate.can_confirm_joined) {
        return {
            kind: 'link',
            label: 'Confirm Joined',
            href: candidateDetailSectionHref(candidate.id, 'joining'),
        };
    }

    if (candidate.can_convert) {
        return {
            kind: 'link',
            label: 'Convert to Employee',
            href: `/organization/employees/create?candidate_id=${candidate.id}`,
        };
    }

    return null;
}

export function blockedNextStepHint(
    candidate: CandidateIndexRow,
): string | null {
    if (resolvePrimaryAction(candidate)) {
        return null;
    }

    const blocked = (candidate.move_destinations ?? []).find(
        (destination) =>
            destination.kind === 'blocked' ||
            destination.kind === 'select_required',
    );

    if (blocked?.blocked_reason) {
        return blocked.blocked_reason;
    }

    if (!candidate.parents_valid) {
        return 'Requirement or position line is closed — reopen parents before moving.';
    }

    if (!candidate.can_move) {
        return 'You need move permission and ownership of this requirement.';
    }

    return null;
}
