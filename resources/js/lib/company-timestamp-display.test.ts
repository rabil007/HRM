import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatCompanyDateFromTimestamp,
    formatDisplayDateTime12hInTimezone,
    toCompanyDateLocal,
} from './company-timezone.ts';
import { formatDisplayDate } from './format-date-core.ts';

describe('company-local timestamp display', () => {
    it('crosses midnight for Home since style offset timestamps', () => {
        const homeSince = '2026-10-15T21:30:00+00:00';
        const display = formatDisplayDateTime12hInTimezone(
            homeSince,
            'Asia/Dubai',
            'd-m-Y',
        );

        assert.match(display, /^16-10-2026 /);
        assert.notEqual(display.slice(0, 10), '15-10-2026');
    });

    it('converts absolute upload timestamps to company calendar days', () => {
        const uploadedAt = '2026-10-15T21:30:00Z';
        const local = toCompanyDateLocal(uploadedAt, 'Asia/Dubai');

        assert.equal(local, '2026-10-16');
        assert.equal(formatDisplayDate(local, 'd-m-Y'), '16-10-2026');
        assert.equal(formatDisplayDate(uploadedAt, 'd-m-Y'), '15-10-2026');
    });

    it('formats activity-history created_at on the company calendar day', () => {
        const createdAt = '2026-10-15T21:30:00Z';

        assert.equal(
            formatCompanyDateFromTimestamp(createdAt, 'Asia/Dubai', 'd-m-Y'),
            '16-10-2026',
        );
        assert.equal(
            formatCompanyDateFromTimestamp(null, 'Asia/Dubai', 'd-m-Y'),
            '—',
        );
        assert.equal(
            formatCompanyDateFromTimestamp('', 'Asia/Dubai', 'd-m-Y'),
            '—',
        );
    });
});
