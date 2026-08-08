import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewMovementActionFormData } from '../types.ts';
import {
    actionUsesDirectP4TourSignoff,
    clearedDirectP4TourFields,
    defaultDestinationTourSignoffChoice,
    findRankTourOption,
    hasManualOverrideInput,
    nextSignoffChoiceForRankChange,
    normalizeTourSignoffPayload,
    shouldShowDirectP4TourSignoffFields,
} from './tour-signoff.ts';

function rank(
    overrides: Partial<{
        id: number;
        name: string;
        max_tour_of_duty_days: number | null;
        resolved_tour_of_duty_days: number | null;
    }> = {},
) {
    return {
        id: 1,
        name: 'Chief Officer',
        max_tour_of_duty_days: 90,
        resolved_tour_of_duty_days: 90,
        ...overrides,
    };
}

function baseForm(
    overrides: Partial<CrewMovementActionFormData> = {},
): CrewMovementActionFormData {
    return {
        action: 'transfer_vessel',
        occurred_at: '2026-08-07T10:00',
        next_phase: '',
        starting_phase: '',
        provider: '',
        course: '',
        planned_start_at: '',
        planned_end_at: '',
        remarks: '',
        vessel_id: 2,
        rank_id: 1,
        client_id: null,
        company_visa_type_id: null,
        planned_signoff_at: '2026-10-01',
        planned_travel_at: '',
        reason: '',
        planned_signoff_choice: 'tour_of_duty',
        planned_signoff_override_reason: 'stale reason',
        ...overrides,
    };
}

describe('defaultDestinationTourSignoffChoice', () => {
    it('defaults transfer destination with Tour to tour_of_duty', () => {
        assert.equal(
            defaultDestinationTourSignoffChoice(rank()),
            'tour_of_duty',
        );
    });

    it('defaults transfer without Tour to manual_override', () => {
        assert.equal(
            defaultDestinationTourSignoffChoice(
                rank({
                    max_tour_of_duty_days: null,
                    resolved_tour_of_duty_days: null,
                }),
            ),
            'manual_override',
        );
    });

    it('never returns existing_plan for destination assignments', () => {
        const withTour = defaultDestinationTourSignoffChoice(rank());
        const withoutTour = defaultDestinationTourSignoffChoice(
            rank({ max_tour_of_duty_days: null, resolved_tour_of_duty_days: null }),
        );

        assert.notEqual(withTour, 'existing_plan');
        assert.notEqual(withoutTour, 'existing_plan');
    });
});

describe('nextSignoffChoiceForRankChange', () => {
    it('uses destination Tour information when destination rank has Tour', () => {
        assert.equal(
            nextSignoffChoiceForRankChange({
                previousChoice: 'manual_override',
                nextRank: rank({
                    id: 2,
                    name: 'Second Officer',
                    max_tour_of_duty_days: 60,
                    resolved_tour_of_duty_days: 60,
                }),
                hasManualOverrideInput: false,
            }),
            'tour_of_duty',
        );
    });

    it('keeps deliberate manual override when user already entered override fields', () => {
        assert.equal(
            nextSignoffChoiceForRankChange({
                previousChoice: 'manual_override',
                nextRank: rank({ max_tour_of_duty_days: 60, resolved_tour_of_duty_days: 60 }),
                hasManualOverrideInput: true,
            }),
            'manual_override',
        );
    });

    it('falls back to manual_override when destination rank has no Tour', () => {
        assert.equal(
            nextSignoffChoiceForRankChange({
                previousChoice: 'tour_of_duty',
                nextRank: rank({
                    max_tour_of_duty_days: null,
                    resolved_tour_of_duty_days: null,
                }),
                hasManualOverrideInput: false,
            }),
            'manual_override',
        );
    });
});

describe('shouldShowDirectP4TourSignoffFields', () => {
    it('shows Tour fields for transfer and join vessel', () => {
        assert.equal(
            shouldShowDirectP4TourSignoffFields('transfer_vessel'),
            true,
        );
        assert.equal(shouldShowDirectP4TourSignoffFields('join_vessel'), true);
    });

    it('shows Tour fields for redeploy P4 only', () => {
        assert.equal(
            shouldShowDirectP4TourSignoffFields('redeploy', 'p4'),
            true,
        );
        assert.equal(
            shouldShowDirectP4TourSignoffFields('redeploy', 'p0'),
            false,
        );
        assert.equal(
            shouldShowDirectP4TourSignoffFields('redeploy', 'p1'),
            false,
        );
        assert.equal(
            shouldShowDirectP4TourSignoffFields('redeploy', 'p2a'),
            false,
        );
        assert.equal(
            shouldShowDirectP4TourSignoffFields('redeploy', 'p3'),
            false,
        );
    });
});

describe('normalizeTourSignoffPayload', () => {
    it('removes stale manual date/reason when Tour choice is selected', () => {
        const payload = normalizeTourSignoffPayload(
            baseForm({
                action: 'transfer_vessel',
                planned_signoff_choice: 'tour_of_duty',
                planned_signoff_at: '2026-10-01',
                planned_signoff_override_reason: 'stale',
            }),
            'transfer_vessel',
        );

        assert.equal('planned_signoff_at' in payload, false);
        assert.equal(payload.planned_signoff_override_reason, '');
        assert.equal(payload.planned_signoff_choice, 'tour_of_duty');
    });

    it('keeps manual override date and reason for transfer', () => {
        const payload = normalizeTourSignoffPayload(
            baseForm({
                action: 'transfer_vessel',
                planned_signoff_choice: 'manual_override',
                planned_signoff_at: '2026-11-01',
                planned_signoff_override_reason: 'Client request',
            }),
            'transfer_vessel',
        );

        assert.equal(payload.planned_signoff_at, '2026-11-01');
        assert.equal(payload.planned_signoff_override_reason, 'Client request');
    });

    it('preserves join vessel existing_plan behaviour', () => {
        const payload = normalizeTourSignoffPayload(
            baseForm({
                action: 'join_vessel',
                planned_signoff_choice: 'existing_plan',
                planned_signoff_at: '2026-09-01',
                planned_signoff_override_reason: 'should clear',
            }),
            'join_vessel',
        );

        assert.equal(payload.planned_signoff_choice, 'existing_plan');
        assert.equal('planned_signoff_at' in payload, false);
        assert.equal(payload.planned_signoff_override_reason, '');
    });

    it('excludes direct-P4 Tour fields for pre-P4 redeploy', () => {
        for (const starting_phase of ['p0', 'p1', 'p2a', 'p3'] as const) {
            const payload = normalizeTourSignoffPayload(
                baseForm({
                    action: 'redeploy',
                    starting_phase,
                    planned_signoff_choice: 'tour_of_duty',
                    planned_signoff_override_reason: 'stale',
                    planned_signoff_at:
                        starting_phase === 'p0' ? '2026-10-01' : '2026-12-01',
                }),
                'redeploy',
            );

            assert.equal('planned_signoff_choice' in payload, false);
            assert.equal('planned_signoff_override_reason' in payload, false);

            if (starting_phase === 'p0') {
                assert.equal(payload.planned_signoff_at, '');
                assert.equal(payload.vessel_id, null);
                assert.equal(payload.rank_id, null);
            } else {
                assert.equal(payload.planned_signoff_at, '2026-12-01');
            }
        }
    });

    it('keeps Tour fields for direct-P4 redeploy', () => {
        const payload = normalizeTourSignoffPayload(
            baseForm({
                action: 'redeploy',
                starting_phase: 'p4',
                planned_signoff_choice: 'tour_of_duty',
            }),
            'redeploy',
        );

        assert.equal(payload.planned_signoff_choice, 'tour_of_duty');
        assert.equal('planned_signoff_at' in payload, false);
    });
});

describe('clearedDirectP4TourFields', () => {
    it('clears stale Tour payload when leaving P4', () => {
        assert.deepEqual(clearedDirectP4TourFields(), {
            planned_signoff_choice: 'manual_override',
            planned_signoff_override_reason: '',
        });
    });
});

describe('actionUsesDirectP4TourSignoff', () => {
    it('initializes P4 redeploy Tour choice from destination rank helpers', () => {
        assert.equal(actionUsesDirectP4TourSignoff('redeploy', 'p4'), true);
        assert.equal(
            defaultDestinationTourSignoffChoice(
                findRankTourOption(
                    [
                        rank({ id: 10, resolved_tour_of_duty_days: 60 }),
                        rank({
                            id: 11,
                            max_tour_of_duty_days: null,
                            resolved_tour_of_duty_days: null,
                        }),
                    ],
                    10,
                ),
            ),
            'tour_of_duty',
        );
        assert.equal(
            defaultDestinationTourSignoffChoice(
                findRankTourOption(
                    [
                        rank({
                            id: 11,
                            max_tour_of_duty_days: null,
                            resolved_tour_of_duty_days: null,
                        }),
                    ],
                    11,
                ),
            ),
            'manual_override',
        );
    });
});

describe('hasManualOverrideInput', () => {
    it('detects override reason or date for transfer manual submit readiness', () => {
        assert.equal(
            hasManualOverrideInput({
                planned_signoff_at: '',
                planned_signoff_override_reason: '',
            }),
            false,
        );
        assert.equal(
            hasManualOverrideInput({
                planned_signoff_at: '2026-11-01',
                planned_signoff_override_reason: '',
            }),
            true,
        );
        assert.equal(
            hasManualOverrideInput({
                planned_signoff_at: '',
                planned_signoff_override_reason: 'Client request',
            }),
            true,
        );
    });
});
