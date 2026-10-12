import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow } from '../types.ts';
import {
    candidateIsStalled,
    candidateNeedsAttention,
} from './candidate-attention.ts';

function row(overrides: Partial<CandidateIndexRow> = {}): CandidateIndexRow {
    return {
        id: 1,
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
        can_move_forward: true,
        can_correct_stage: false,
        move_destinations: [],
        can_reject: true,
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

describe('candidate attention helpers', () => {
    it('flags joining urgency, blockers, and pending conversion', () => {
        assert.equal(candidateNeedsAttention(row()), false);
        assert.equal(
            candidateNeedsAttention(
                row({ joining_schedule_urgency: 'overdue' }),
            ),
            true,
        );
        assert.equal(
            candidateNeedsAttention(
                row({ joining_blocker_notes: 'Visa delay' }),
            ),
            true,
        );
        assert.equal(
            candidateNeedsAttention(
                row({
                    stage: 'joined',
                    conversion_status: 'pending',
                }),
            ),
            true,
        );
    });

    it('flags stalled waiting time without inventing a status', () => {
        assert.equal(candidateIsStalled(row({ days_in_stage: 3 })), false);
        assert.equal(candidateIsStalled(row({ days_in_stage: 7 })), true);
        assert.equal(
            candidateIsStalled(row({ stage: 'joined', days_in_stage: 20 })),
            false,
        );
    });
});
