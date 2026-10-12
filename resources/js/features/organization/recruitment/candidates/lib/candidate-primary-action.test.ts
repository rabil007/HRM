import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow, CandidateMoveDestination } from '../types.ts';
import { resolvePrimaryAction } from './candidate-primary-action.ts';

function row(overrides: Partial<CandidateIndexRow> = {}): CandidateIndexRow {
    return {
        id: 42,
        name: 'Ada',
        email: null,
        phone: null,
        stage: 'applied',
        stage_label: 'Applied',
        stage_badge: 'secondary',
        interview_outcome: null,
        interview_outcome_label: null,
        interview_outcome_badge: null,
        offer_status: null,
        offer_status_label: null,
        offer_status_badge: null,
        requirement_id: 1,
        requirement_number: 'REQ-1',
        requirement_line_id: 1,
        position_title: 'Officer',
        source: null,
        source_label: null,
        nationality: null,
        has_cv: false,
        interview_scheduled_at: null,
        interview_feedback: null,
        expected_joining_date: null,
        actual_joining_date: null,
        joining_readiness_status: null,
        joining_readiness_notes: null,
        joining_blocker_notes: null,
        joining_schedule_urgency: null,
        joining_schedule_label: null,
        days_in_stage: 0,
        lock_version: 1,
        created_at: null,
        parents_valid: true,
        can_update: true,
        can_update_interview: true,
        can_move: true,
        can_move_forward: false,
        can_correct_stage: false,
        move_destinations: [],
        can_reject: false,
        can_select: false,
        can_undo_selected: false,
        can_prepare_offer: false,
        can_reopen: false,
        can_download_cv: false,
        can_confirm_joined: false,
        can_correct_joined: false,
        can_update_readiness: false,
        can_convert: false,
        conversion_status: 'not_applicable',
        joined_by_name: null,
        joined_at: null,
        ...overrides,
    };
}

describe('resolvePrimaryAction', () => {
    it('prefers Confirm Joined over Update Readiness when both are permitted', () => {
        const confirm: CandidateMoveDestination = {
            stage: 'joined',
            kind: 'confirm_joined',
            label: 'Confirm Joined',
            blocked_reason: null,
        };

        const action = resolvePrimaryAction(
            row({
                stage: 'offer_jol',
                offer_status: 'accepted',
                can_update_readiness: true,
                can_confirm_joined: true,
                move_destinations: [confirm],
            }),
        );

        assert.equal(action?.kind, 'destination');
        assert.equal(action?.label, 'Confirm Joined');
    });

    it('shows Update Readiness when Confirm Joined is not permitted', () => {
        const action = resolvePrimaryAction(
            row({
                stage: 'offer_jol',
                offer_status: 'accepted',
                can_update_readiness: true,
                can_confirm_joined: false,
            }),
        );

        assert.equal(action?.kind, 'update_readiness');
        assert.equal(action?.label, 'Update Readiness');
        assert.equal(
            action?.kind === 'update_readiness' ? action.href : null,
            '/organization/recruitment/candidates/42#joining',
        );
    });

    it('opens Mark Selected through the select action kind', () => {
        const action = resolvePrimaryAction(
            row({
                stage: 'interview',
                can_select: true,
            }),
        );

        assert.equal(action?.kind, 'select');
        assert.equal(action?.label, 'Mark Selected');
    });

    it('opens Prepare Offer through the prepare_offer action kind', () => {
        const action = resolvePrimaryAction(
            row({
                stage: 'interview',
                interview_outcome: 'selected',
                can_prepare_offer: true,
                move_destinations: [
                    {
                        stage: 'offer_jol',
                        kind: 'prepare_offer',
                        label: 'Prepare Offer',
                        blocked_reason: null,
                    },
                ],
            }),
        );

        assert.equal(action?.kind, 'prepare_offer');
        assert.equal(action?.label, 'Prepare Offer');
    });

    it('shows Complete Draft Offer before readiness actions', () => {
        const action = resolvePrimaryAction(
            row({
                stage: 'offer_jol',
                offer_status: 'draft',
                can_update_readiness: true,
                can_confirm_joined: false,
            }),
        );

        assert.equal(action?.label, 'Complete Draft Offer');
    });
});
