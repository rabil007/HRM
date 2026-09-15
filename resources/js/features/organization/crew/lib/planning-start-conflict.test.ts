import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canUseManualTransferRecommendation,
    hasPlanningStartActiveAssignmentConflict,
    shouldShowPlanningTransferGuidance,
} from './planning-start-conflict.ts';

const currentOnVessel = {
    vessel_id: 638,
    can_transfer: true,
};

const activeEmployeeStatus = {
    status: 'on_vessel',
    label: 'On Vessel',
    current_phase: 'p4',
    current_vessel: 'Vessel A',
    assignment_id: 1,
    assignment_no: 'CA-2026-000123',
    since: null,
    days_in_phase: 3,
    planned_next_date: null,
    warning: null,
    in_home_days: null,
    vessel_name: 'Vessel A',
    has_active_assignment: true,
};

describe('canUseManualTransferRecommendation', () => {
    it('returns false for planning handoff even when transfer would otherwise apply', () => {
        assert.equal(
            canUseManualTransferRecommendation(
                true,
                false,
                currentOnVessel,
                649,
            ),
            false,
        );
    });

    it('returns true for manual create when on vessel on a different destination', () => {
        assert.equal(
            canUseManualTransferRecommendation(
                false,
                false,
                currentOnVessel,
                649,
            ),
            true,
        );
    });

    it('returns false in bulk mode', () => {
        assert.equal(
            canUseManualTransferRecommendation(
                false,
                true,
                currentOnVessel,
                649,
            ),
            false,
        );
    });
});

describe('hasPlanningStartActiveAssignmentConflict', () => {
    it('blocks planning start when the employee already has an active assignment', () => {
        assert.equal(
            hasPlanningStartActiveAssignmentConflict(
                true,
                false,
                activeEmployeeStatus,
                false,
            ),
            true,
        );
    });

    it('does not treat manual transfer recommendation as a planning conflict bypass', () => {
        assert.equal(
            hasPlanningStartActiveAssignmentConflict(
                true,
                false,
                activeEmployeeStatus,
                true,
            ),
            true,
        );
    });

    it('allows manual create to defer to transfer recommendation', () => {
        assert.equal(
            hasPlanningStartActiveAssignmentConflict(
                false,
                false,
                activeEmployeeStatus,
                true,
            ),
            false,
        );
    });
});

describe('shouldShowPlanningTransferGuidance', () => {
    it('shows transfer guidance only for on vessel moves to a different vessel', () => {
        assert.equal(
            shouldShowPlanningTransferGuidance(
                activeEmployeeStatus,
                currentOnVessel,
                649,
            ),
            true,
        );
        assert.equal(
            shouldShowPlanningTransferGuidance(
                activeEmployeeStatus,
                currentOnVessel,
                638,
            ),
            false,
        );
    });
});
