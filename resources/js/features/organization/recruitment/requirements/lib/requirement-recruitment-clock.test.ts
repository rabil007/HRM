import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    resolveActiveRecruitmentDurationDisplay,
    resolveRecruitmentClockSummary,
} from './requirement-recruitment-clock.ts';

describe('requirement recruitment clock', () => {
    it('summarizes not started state', () => {
        assert.equal(
            resolveRecruitmentClockSummary({
                clockState: 'not_started',
                durationLabel: null,
                startedAtFormatted: null,
            }),
            'Not started',
        );
    });

    it('includes active duration when running', () => {
        assert.equal(
            resolveRecruitmentClockSummary({
                clockState: 'running',
                durationLabel: '3 days',
                startedAtFormatted: '01-10-2026 09:00',
            }),
            'Running · 3 days active',
        );
    });

    it('describes paused on hold state', () => {
        assert.match(
            resolveRecruitmentClockSummary({
                clockState: 'paused',
                durationLabel: '2 days',
                startedAtFormatted: null,
            }),
            /Paused/,
        );
    });
});

describe('active recruitment duration display', () => {
    it('does not mark exact durations as estimated', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                durationLabel: '3 days',
                isEstimated: false,
                estimateNote: null,
            }),
            {
                label: '3 days',
                showEstimated: false,
                estimateNote: null,
            },
        );
    });

    it('shows estimated indicator and note for estimated durations', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                durationLabel: '2 days',
                isEstimated: true,
                estimateNote:
                    'On-hold duration is estimated from the last update timestamp because no hold transition history exists.',
            }),
            {
                label: '2 days',
                showEstimated: true,
                estimateNote:
                    'On-hold duration is estimated from the last update timestamp because no hold transition history exists.',
            },
        );
    });

    it('does not show estimated UI when there is no duration label', () => {
        assert.deepEqual(
            resolveActiveRecruitmentDurationDisplay({
                durationLabel: null,
                isEstimated: true,
                estimateNote: 'Should stay hidden',
            }),
            {
                label: null,
                showEstimated: false,
                estimateNote: null,
            },
        );
    });
});
