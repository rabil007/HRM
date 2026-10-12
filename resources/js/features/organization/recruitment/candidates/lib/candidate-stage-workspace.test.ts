import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateDetail } from '../types.ts';
import { CANDIDATE_STAGE_LABELS } from '../types.ts';
import {
    buildPipelineSteps,
    hasInterviewDetails,
    resolveWorkspaceFocus,
    shouldPrioritizeInterview,
    shouldPrioritizeJoiningPrep,
    shouldPrioritizeOffer,
    shouldShowJoiningPanel,
    shouldShowOfferPanel,
} from './candidate-stage-workspace.ts';

function detail(overrides: Partial<CandidateDetail> = {}): CandidateDetail {
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
        expected_joining_date: null,
        actual_joining_date: null,
        joining_readiness_status: null,
        joining_readiness_notes: null,
        joining_blocker_notes: null,
        joining_schedule_urgency: null,
        joining_schedule_label: null,
        days_in_stage: 2,
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
        notes: null,
        rejection_reason: null,
        pre_rejection_stage: null,
        cv_original_file_name: null,
        nationality_id: null,
        interview: {
            scheduled_at: null,
            scheduled_at_formatted: null,
            interviewer_user_id: null,
            interviewer_user_name: null,
            external_interviewer_name: null,
            mode: null,
            mode_label: null,
            location: null,
            notes: null,
            feedback: null,
            can_edit: true,
            edit_blocked_reason: null,
        },
        requirement: null,
        line: null,
        current_offer: null,
        offer_history: [],
        joining: null,
        movement_history: [],
        timezone: 'Asia/Dubai',
        ...overrides,
    };
}

describe('candidate-stage-workspace', () => {
    it('maps historical joining to offer_jol focus', () => {
        assert.equal(resolveWorkspaceFocus('joining'), 'offer_jol');
        assert.equal(resolveWorkspaceFocus('interview'), 'interview');
    });

    it('builds pipeline with current and completed stages', () => {
        const steps = buildPipelineSteps('interview', CANDIDATE_STAGE_LABELS);

        assert.equal(steps[0]?.status, 'complete');
        assert.equal(steps[1]?.status, 'complete');
        assert.equal(steps[2]?.status, 'current');
        assert.equal(steps[3]?.status, 'upcoming');
        assert.equal(steps[4]?.status, 'upcoming');
    });

    it('prioritizes interview and offer panels by stage', () => {
        assert.equal(
            shouldPrioritizeInterview(detail({ stage: 'interview' })),
            true,
        );
        assert.equal(
            shouldPrioritizeOffer(detail({ stage: 'offer_jol' })),
            true,
        );
        assert.equal(
            shouldShowOfferPanel(
                detail({
                    stage: 'interview',
                    can_prepare_offer: true,
                }),
            ),
            true,
        );
        assert.equal(
            shouldShowJoiningPanel(
                detail({
                    stage: 'offer_jol',
                    current_offer: {
                        id: 1,
                        status: 'accepted',
                    } as CandidateDetail['current_offer'],
                }),
            ),
            true,
        );
    });

    it('prioritizes joining prep only after an accepted offer', () => {
        assert.equal(
            shouldPrioritizeJoiningPrep(
                detail({
                    stage: 'offer_jol',
                    offer_status: 'sent',
                }),
            ),
            false,
        );
        assert.equal(
            shouldPrioritizeJoiningPrep(
                detail({
                    stage: 'offer_jol',
                    offer_status: 'accepted',
                }),
            ),
            true,
        );
    });

    it('detects interview detail presence', () => {
        assert.equal(hasInterviewDetails(detail()), false);
        assert.equal(
            hasInterviewDetails(
                detail({
                    interview: {
                        ...detail().interview,
                        mode: 'video',
                    },
                }),
            ),
            true,
        );
    });
});
