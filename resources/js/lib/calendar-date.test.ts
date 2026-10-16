import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { addIsoCalendarDays, previousIsoCalendarDay } from './calendar-date.ts';

describe('calendar-date helpers', () => {
    it('subtracts one calendar day without UTC shift', () => {
        assert.equal(previousIsoCalendarDay('2026-07-07'), '2026-07-06');
    });

    it('handles month and year boundaries', () => {
        assert.equal(previousIsoCalendarDay('2026-03-01'), '2026-02-28');
        assert.equal(previousIsoCalendarDay('2026-01-01'), '2025-12-31');
        assert.equal(addIsoCalendarDays('2026-02-28', 1), '2026-03-01');
    });

    it('handles leap years', () => {
        assert.equal(previousIsoCalendarDay('2024-03-01'), '2024-02-29');
        assert.equal(addIsoCalendarDays('2024-02-28', 1), '2024-02-29');
        assert.equal(previousIsoCalendarDay('2025-03-01'), '2025-02-28');
    });

    it('returns null for invalid or empty values', () => {
        assert.equal(previousIsoCalendarDay(null), null);
        assert.equal(previousIsoCalendarDay(''), null);
        assert.equal(previousIsoCalendarDay('07-07-2026'), null);
        assert.equal(previousIsoCalendarDay('2026-02-30'), null);
    });

    it('stays stable across browser timezone offsets', () => {
        const original = process.env.TZ;
        process.env.TZ = 'America/Los_Angeles';

        try {
            assert.equal(previousIsoCalendarDay('2026-07-07'), '2026-07-06');
            assert.equal(addIsoCalendarDays('2026-10-16', 0), '2026-10-16');
        } finally {
            if (original === undefined) {
                delete process.env.TZ;
            } else {
                process.env.TZ = original;
            }
        }
    });
});
