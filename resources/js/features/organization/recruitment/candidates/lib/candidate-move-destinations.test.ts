import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow, CandidateMoveDestination } from '../types.ts';
import {
    actionableDestinations,
    destinationForStage,
    isDropAllowed,
} from './candidate-move-destinations.ts';

function row(
    destinations: CandidateMoveDestination[],
    overrides: Partial<CandidateIndexRow> = {},
): CandidateIndexRow {
    return {
        id: 1,
        name: 'Ada',
        email: null,
        phone: null,
        stage: 'screening',
        stage_label: 'Screening',
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
        lock_version: 0,
        created_at: null,
        parents_valid: true,
        can_update: true,
        can_update_interview: true,
        can_move: true,
        can_move_forward: true,
        can_correct_stage: true,
        move_destinations: destinations,
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

describe('candidate-move-destinations', () => {
    it('filters actionable destinations for menus and drops', () => {
        const destinations: CandidateMoveDestination[] = [
            {
                stage: 'interview',
                kind: 'forward',
                label: 'Move to Interview',
                blocked_reason: null,
            },
            {
                stage: 'applied',
                kind: 'backward',
                label: 'Correct to Applied',
                blocked_reason: null,
            },
            {
                stage: 'offer_jol',
                kind: 'select_required',
                label: 'Offer/JOL',
                blocked_reason: 'Mark Selected first',
            },
            {
                stage: 'joined',
                kind: 'blocked',
                label: 'Joined',
                blocked_reason: 'Not ready',
            },
        ];

        const candidate = row(destinations);

        assert.equal(actionableDestinations(candidate).length, 2);
        assert.equal(isDropAllowed(candidate, 'interview'), true);
        assert.equal(isDropAllowed(candidate, 'applied'), true);
        assert.equal(isDropAllowed(candidate, 'offer_jol'), false);
        assert.equal(isDropAllowed(candidate, 'joined'), false);
        assert.equal(isDropAllowed(candidate, 'rejected'), false);
        assert.equal(
            destinationForStage(candidate, 'interview')?.kind,
            'forward',
        );
    });

    it('keeps drag and Move to Stage parity on allowed kinds', () => {
        const destinations: CandidateMoveDestination[] = [
            {
                stage: 'joined',
                kind: 'confirm_joined',
                label: 'Confirm Joined',
                blocked_reason: null,
            },
            {
                stage: 'offer_jol',
                kind: 'prepare_offer',
                label: 'Prepare Offer/JOL',
                blocked_reason: null,
            },
            {
                stage: 'pre_rejection',
                kind: 'reopen',
                label: 'Reopen',
                blocked_reason: null,
            },
        ];

        const candidate = row(destinations, { stage: 'offer_jol' });
        const actionable = actionableDestinations(candidate);

        assert.deepEqual(
            actionable.map((destination) => destination.kind),
            ['confirm_joined', 'prepare_offer', 'reopen'],
        );
        assert.equal(isDropAllowed(candidate, 'joined'), true);
        assert.equal(isDropAllowed(candidate, 'offer_jol'), true);
    });
});
