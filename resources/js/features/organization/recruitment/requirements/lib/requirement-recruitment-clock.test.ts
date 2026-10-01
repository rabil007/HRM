import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveRecruitmentClockSummary } from './requirement-recruitment-clock.ts';

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
