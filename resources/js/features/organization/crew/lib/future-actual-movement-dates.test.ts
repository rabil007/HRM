import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    RECORD_NOW_EXPLANATION,
    SCHEDULE_PERMISSION_MESSAGE,
    formatScheduledMovementExplanation,
    isSchedulableMovementAction,
    resolveAutomaticSchedulingMode,
    resolveMovementOccurredAtMax,
    resolveMovementOccurredAtMin,
    resolveMovementSubmitLabel,
    shouldBlockFutureActualMovementDate,
    shouldShowFutureMovementWarning,
} from './future-actual-movement-dates.ts';

describe('automatic movement timing helpers', () => {
    it('exposes record-now and schedule explanation copy', () => {
        assert.match(RECORD_NOW_EXPLANATION, /operational phase will update/i);
        assert.match(
            formatScheduledMovementExplanation('15 Oct 2026, 8:59 AM'),
            /Scheduled for 15 Oct 2026, 8:59 AM/,
        );
        assert.match(SCHEDULE_PERMISSION_MESSAGE, /permission to schedule/i);
    });

    it('limits record mode to company now and warns on future non-schedulable times', () => {
        assert.equal(
            resolveMovementOccurredAtMax('2026-09-25T12:00'),
            '2026-09-25T12:00',
        );
        assert.equal(shouldShowFutureMovementWarning(true), true);
        assert.equal(shouldShowFutureMovementWarning(false), false);
        assert.equal(shouldBlockFutureActualMovementDate(true), true);
    });

    it('allows future timestamps for a schedulable action before scheduling mode is on', () => {
        assert.equal(
            resolveMovementOccurredAtMax('2026-09-25T12:00', true),
            undefined,
        );
        assert.equal(
            resolveMovementOccurredAtMin('2026-09-25T12:00'),
            undefined,
        );
        assert.equal(
            resolveMovementOccurredAtMin('2026-09-25T12:00', {
                chronologyMin: '2026-09-20T00:00',
            }),
            '2026-09-20T00:00',
        );
        assert.equal(shouldShowFutureMovementWarning(true, true), false);
        assert.equal(shouldBlockFutureActualMovementDate(true, true), false);
    });

    it('keeps company now as the minimum only while editing an existing schedule', () => {
        assert.equal(
            resolveMovementOccurredAtMin('2026-09-25T12:00', {
                preventPastSelection: true,
            }),
            '2026-09-25T12:00',
        );
        assert.equal(
            resolveMovementOccurredAtMin('2026-09-25T12:00', {
                preventPastSelection: true,
                chronologyMin: '2026-09-28T00:00',
            }),
            '2026-09-28T00:00',
        );
    });

    it('uses Schedule Movement only after a future time on a schedulable action', () => {
        assert.equal(resolveAutomaticSchedulingMode(false, true, false), false);
        assert.equal(resolveAutomaticSchedulingMode(true, false, false), false);
        assert.equal(resolveAutomaticSchedulingMode(true, true, false), true);
        assert.equal(
            resolveMovementSubmitLabel(false, false, 'Record Movement'),
            'Record Movement',
        );
        assert.equal(
            resolveMovementSubmitLabel(true, false, 'Record Movement'),
            'Schedule Movement',
        );
        assert.equal(
            resolveMovementSubmitLabel(true, true, 'Record Movement'),
            'Save Changes',
        );
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
