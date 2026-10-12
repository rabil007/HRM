import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow } from '../types.ts';
import {
    buildRecruiterNextAction,
    resolveCurrentStateLabel,
} from './candidate-recruiter-next-action.ts';

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
        has_cv: true,
        interview_scheduled_at: null,
        expected_joining_date: null,
        actual_joining_date: null,
        joining_readiness_status: null,
        joining_readiness_notes: null,
        joining_blocker_notes: null,
        joining_schedule_urgency: null,
        joining_schedule_label: null,
        days_in_stage: 1,
        lock_version: 1,
        created_at: null,
        parents_valid: true,
        can_update: true,
        can_update_interview: false,
        can_move: true,
        can_move_forward: true,
        can_correct_stage: false,
        move_destinations: [
            {
                stage: 'screening',
                kind: 'forward',
                label: 'Move to Screening',
                blocked_reason: null,
            },
        ],
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

describe('candidate-recruiter-next-action', () => {
    it('labels interview and offer states clearly', () => {
        assert.equal(
            resolveCurrentStateLabel(
                row({
                    stage: 'interview',
                    stage_label: 'Interview',
                    interview_outcome: null,
                }),
            ),
            'Interview — Pending Decision',
        );
        assert.equal(
            resolveCurrentStateLabel(
                row({
                    stage: 'interview',
                    interview_outcome: 'selected',
                }),
            ),
            'Interview — Selected',
        );
        assert.equal(
            resolveCurrentStateLabel(
                row({
                    stage: 'offer_jol',
                    stage_label: 'Offer/JOL',
                    offer_status: 'accepted',
                    joining_readiness_status: 'pending',
                }),
            ),
            'Offer/JOL — Accepted, Readiness Pending',
        );
    });

    it('surfaces one recommended action and secondary options', () => {
        const model = buildRecruiterNextAction(
            row({
                can_reject: true,
                can_correct_stage: true,
                move_destinations: [
                    {
                        stage: 'screening',
                        kind: 'forward',
                        label: 'Move to Screening',
                        blocked_reason: null,
                    },
                ],
            }),
        );

        assert.equal(model.recommended?.label, 'Move to Screening');
        assert.equal(model.currentState, 'Applied');
        assert.ok(model.why?.includes('Advance'));
        assert.ok(model.otherActions.some((action) => action.key === 'reject'));
    });

    it('lists attention items without inventing workflow requirements', () => {
        const model = buildRecruiterNextAction(
            row({
                has_cv: false,
                parents_valid: false,
                joining_blocker_notes: 'Visa pending',
            }),
        );

        assert.ok(model.attention.some((item) => item.includes('no CV')));
        assert.ok(
            model.attention.some((item) => item.includes('closed or missing')),
        );
        assert.ok(
            model.attention.some((item) => item.includes('Joining blocker')),
        );
    });
});
