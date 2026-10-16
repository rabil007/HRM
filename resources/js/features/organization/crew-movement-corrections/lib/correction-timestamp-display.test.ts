import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatCorrectionRequestedDate,
    formatCorrectionTimestamp,
} from './correction-timestamp-display.ts';

describe('crew movement correction timestamps', () => {
    const dubaiMidnightCrossing = '2026-10-15T21:30:00.000Z'; // 01:30 Asia/Dubai on 16th

    it('aligns date-only Requested with Requested at on Dubai midnight boundaries', () => {
        const dateOnly = formatCorrectionRequestedDate(
            dubaiMidnightCrossing,
            'Asia/Dubai',
            'd-m-Y',
        );
        const dateTime = formatCorrectionTimestamp(
            dubaiMidnightCrossing,
            'Asia/Dubai',
            'd-m-Y',
        );

        assert.equal(dateOnly, '16-10-2026');
        assert.match(dateTime, /^16-10-2026 /);
        assert.notEqual(dateOnly, '15-10-2026');
    });

    it('uses company timezone rather than the browser local calendar day', () => {
        const instant = '2026-10-16T02:00:00.000Z';

        assert.equal(
            formatCorrectionRequestedDate(instant, 'America/New_York', 'd-m-Y'),
            '15-10-2026',
        );
        assert.equal(
            formatCorrectionRequestedDate(instant, 'Asia/Dubai', 'd-m-Y'),
            '16-10-2026',
        );
        assert.match(
            formatCorrectionTimestamp(instant, 'America/New_York', 'd-m-Y'),
            /^15-10-2026 /,
        );
        assert.match(
            formatCorrectionTimestamp(instant, 'Asia/Dubai', 'd-m-Y'),
            /^16-10-2026 /,
        );
    });

    it('preserves null decided_at as an em dash', () => {
        assert.equal(
            formatCorrectionTimestamp(null, 'Asia/Dubai', 'd-m-Y'),
            '—',
        );
        assert.equal(
            formatCorrectionTimestamp(undefined, 'Asia/Dubai', 'd-m-Y'),
            '—',
        );
    });
});
