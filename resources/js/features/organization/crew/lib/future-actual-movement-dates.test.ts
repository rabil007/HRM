import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    TESTING_OVERRIDE_BANNER_MESSAGE,
    resolveMovementOccurredAtMax,
    shouldBlockFutureActualMovementDate,
    shouldShowFutureMovementWarning,
    shouldShowTestingOverrideBanner,
} from './future-actual-movement-dates.ts';

describe('future actual movement dates helpers', () => {
    it('exposes the testing-mode banner copy for immediate (not scheduled) movements', () => {
        assert.match(
            TESTING_OVERRIDE_BANNER_MESSAGE,
            /Testing Mode: Future-dated movements are recorded immediately/,
        );
        assert.doesNotMatch(TESTING_OVERRIDE_BANNER_MESSAGE, /schedul/i);
        assert.doesNotMatch(TESTING_OVERRIDE_BANNER_MESSAGE, /pending/i);
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

    it('blocks correction progress for future actual dates only when override is off', () => {
        assert.equal(shouldBlockFutureActualMovementDate(true, false), true);
        assert.equal(shouldBlockFutureActualMovementDate(true, true), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, false), false);
        assert.equal(shouldBlockFutureActualMovementDate(false, true), false);
    });
});
