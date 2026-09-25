import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    resolveMovementOccurredAtMax,
    shouldBlockFutureActualMovementDate,
    shouldShowFutureMovementWarning,
    shouldShowPlannedConflictAction,
    shouldShowTestingOverrideBanner,
} from './future-actual-movement-dates.ts';

describe('future actual movement dates helpers', () => {
    it('keeps max=companyNow and shows future warning when override is off', () => {
        assert.equal(
            resolveMovementOccurredAtMax('2026-09-25T12:00', false),
            '2026-09-25T12:00',
        );
        assert.equal(shouldShowFutureMovementWarning(true, false), true);
        assert.equal(shouldShowFutureMovementWarning(false, false), false);
        assert.equal(shouldShowTestingOverrideBanner(false), false);
    });

    it('removes max and suppresses future warning when override is on', () => {
        assert.equal(
            resolveMovementOccurredAtMax('2026-09-25T12:00', true),
            undefined,
        );
        assert.equal(shouldShowFutureMovementWarning(true, true), false);
        assert.equal(shouldShowTestingOverrideBanner(true), true);
    });

    it('blocks correction progress for future actual dates only when override is off', () => {
        assert.equal(shouldBlockFutureActualMovementDate(true, false), true);
        assert.equal(shouldBlockFutureActualMovementDate(true, true), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, false), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, true), false);
    });

    it('shows Planned conflict actions from allowed_actions without create-page can flags', () => {
        const allowed = ['edit_existing_plan', 'cancel_existing_plan'];

        assert.equal(
            shouldShowPlannedConflictAction(allowed, 'edit_existing_plan'),
            true,
        );
        assert.equal(
            shouldShowPlannedConflictAction(allowed, 'cancel_existing_plan'),
            true,
        );
        assert.equal(
            shouldShowPlannedConflictAction(
                ['adjust_dates'],
                'edit_existing_plan',
            ),
            false,
        );
    });
});
