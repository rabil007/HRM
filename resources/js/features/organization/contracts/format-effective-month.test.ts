import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { formatEffectiveMonth } from './format-effective-month.ts';

describe('formatEffectiveMonth', () => {
    it('formats month-start ISO dates as short month and year', () => {
        assert.equal(formatEffectiveMonth('2026-10-01'), 'Oct 2026');
        assert.equal(formatEffectiveMonth('2026-01-01'), 'Jan 2026');
        assert.equal(formatEffectiveMonth('2025-12-01'), 'Dec 2025');
    });

    it('accepts YYYY-MM month input values', () => {
        assert.equal(formatEffectiveMonth('2026-10'), 'Oct 2026');
    });

    it('returns em dash for empty values', () => {
        assert.equal(formatEffectiveMonth(null), '—');
        assert.equal(formatEffectiveMonth(undefined), '—');
        assert.equal(formatEffectiveMonth(''), '—');
        assert.equal(formatEffectiveMonth('   '), '—');
    });

    it('does not invent a calendar day from platform full-date formatting', () => {
        assert.notEqual(formatEffectiveMonth('2026-10-01'), '2026-10-01');
        assert.notEqual(formatEffectiveMonth('2026-10-01'), '01-10-2026');
        assert.notEqual(formatEffectiveMonth('2026-10-01'), '10/01/2026');
    });
});
