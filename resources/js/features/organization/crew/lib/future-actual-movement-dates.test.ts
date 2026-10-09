import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    SCHEDULE_LATER_HELP,
    TESTING_OVERRIDE_BANNER_MESSAGE,
    isSchedulableMovementAction,
    resolveMovementOccurredAtMax,
    resolveMovementOccurredAtMin,
    shouldBlockFutureActualMovementDate,
    shouldShowFutureMovementWarning,
    shouldShowTestingOverrideBanner,
} from './future-actual-movement-dates.ts';

describe('future actual movement dates helpers', () => {
    it('exposes legacy override banner copy that points operators to Schedule for Later', () => {
        assert.match(TESTING_OVERRIDE_BANNER_MESSAGE, /Schedule for Later/i);
        assert.match(SCHEDULE_LATER_HELP, /does not change the current phase/i);
    });

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

    it('allows future timestamps in schedule mode without showing the record-now warning', () => {
        assert.equal(
            resolveMovementOccurredAtMax('2026-09-25T12:00', false, true),
            undefined,
        );
        assert.equal(
            resolveMovementOccurredAtMin('2026-09-25T12:00', true),
            '2026-09-25T12:00',
        );
        assert.equal(shouldShowFutureMovementWarning(true, false, true), false);
        assert.equal(shouldShowTestingOverrideBanner(true, true), false);
        assert.equal(
            shouldBlockFutureActualMovementDate(true, false, true),
            false,
        );
    });

    it('blocks correction progress for future actual dates only when override is off', () => {
        assert.equal(shouldBlockFutureActualMovementDate(true, false), true);
        assert.equal(shouldBlockFutureActualMovementDate(true, true), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, false), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, true), false);
    });

    it('classifies schedulable movement actions', () => {
        assert.equal(
            isSchedulableMovementAction('join_vessel', ['join_vessel']),
            true,
        );
        assert.equal(
            isSchedulableMovementAction('plan_signoff', ['join_vessel']),
            false,
        );
    });
});
