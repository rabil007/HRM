import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatRecruitmentDurationInDays,
    resolveActiveRecruitmentDurationDisplay,
} from './requirement-recruitment-clock.ts';

describe('formatRecruitmentDurationInDays', () => {
    it('returns Not started when duration is unavailable', () => {
        assert.equal(formatRecruitmentDurationInDays(null), 'Not started');
        assert.equal(formatRecruitmentDurationInDays(undefined), 'Not started');
    });

    it('returns Less than 1 day for durations under 24 hours', () => {
        assert.equal(formatRecruitmentDurationInDays(0), 'Less than 1 day');
        assert.equal(
            formatRecruitmentDurationInDays(3 * 3600),
            'Less than 1 day',
        );
        assert.equal(
            formatRecruitmentDurationInDays(86400 - 1),
            'Less than 1 day',
        );
    });

    it('uses singular and plural day labels', () => {
        assert.equal(formatRecruitmentDurationInDays(86400), '1 day');
        assert.equal(formatRecruitmentDurationInDays(2 * 86400), '2 days');
        assert.equal(formatRecruitmentDurationInDays(26 * 86400), '26 days');
    });

    it('floors partial days without showing hours or minutes', () => {
        assert.equal(formatRecruitmentDurationInDays(86400 + 3600), '1 day');
        assert.equal(
            formatRecruitmentDurationInDays(8 * 86400 + 100),
            '8 days',
        );
        assert.match(
            formatRecruitmentDurationInDays(90000),
            /^(Less than 1 day|\d+ days?)$/,
        );
        assert.ok(!formatRecruitmentDurationInDays(90000).includes('hour'));
        assert.ok(!formatRecruitmentDurationInDays(90000).includes('minute'));
        assert.ok(!formatRecruitmentDurationInDays(90000).includes('second'));
    });
});

describe('active recruitment duration display', () => {
    it('shows Not started before recruitment begins', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'not_started',
                activeSeconds: null,
            }),
            {
                label: 'Not started',
                showEstimated: false,
                estimateNote: null,
            },
        );
    });

    it('shows running durations in days', () => {
        assert.equal(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'running',
                activeSeconds: 2 * 86400,
            }).label,
            '2 days',
        );
        assert.equal(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'running',
                activeSeconds: 3600,
            }).label,
            'Less than 1 day',
        );
    });

    it('prefixes paused and completed durations', () => {
        assert.equal(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'paused',
                activeSeconds: 8 * 86400,
            }).label,
            'Paused at 8 days',
        );
        assert.equal(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'completed',
                activeSeconds: 14 * 86400,
            }).label,
            'Completed in 14 days',
        );
    });

    it('preserves estimated badge metadata for estimated durations', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'paused',
                activeSeconds: 2 * 86400,
                isEstimated: true,
                estimateNote:
                    'On-hold duration is estimated from the last update timestamp because no hold transition history exists.',
            }),
            {
                label: 'Paused at 2 days',
                showEstimated: true,
                estimateNote:
                    'On-hold duration is estimated from the last update timestamp because no hold transition history exists.',
            },
        );
    });

    it('does not show estimated UI when recruitment has not started', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                clockState: 'not_started',
                activeSeconds: null,
                isEstimated: true,
                estimateNote: 'Should stay hidden',
            }),
            {
                label: 'Not started',
                showEstimated: false,
                estimateNote: null,
            },
        );
    });
});
