import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { suggestedPlannedSignoffDate } from './tour-of-duty.ts';

describe('suggestedPlannedSignoffDate', () => {
    it('calculates 90 days tour of duty from 2026-08-12 to 2026-11-10', () => {
        assert.equal(
            suggestedPlannedSignoffDate('2026-08-12', 90),
            '2026-11-10',
        );
    });

    it('handles ISO timestamps with time component by extracting calendar date', () => {
        assert.equal(
            suggestedPlannedSignoffDate('2026-08-12T10:30:00Z', 90),
            '2026-11-10',
        );
    });

    it('crosses month and year boundaries correctly', () => {
        assert.equal(
            suggestedPlannedSignoffDate('2026-12-31', 1),
            '2027-01-01',
        );
        assert.equal(
            suggestedPlannedSignoffDate('2026-12-01', 45),
            '2027-01-15',
        );
    });

    it('handles leap year calendar arithmetic correctly', () => {
        // 2024 is a leap year (Feb 29 exists)
        assert.equal(
            suggestedPlannedSignoffDate('2024-02-28', 1),
            '2024-02-29',
        );
        assert.equal(
            suggestedPlannedSignoffDate('2024-02-28', 2),
            '2024-03-01',
        );

        // 2026 is a common year (no Feb 29)
        assert.equal(
            suggestedPlannedSignoffDate('2026-02-28', 1),
            '2026-03-01',
        );
    });

    it('returns null for invalid or non-positive tour days', () => {
        assert.equal(suggestedPlannedSignoffDate('2026-08-12', 0), null);
        assert.equal(suggestedPlannedSignoffDate('2026-08-12', -10), null);
        assert.equal(suggestedPlannedSignoffDate('2026-08-12', 15.5), null);
    });

    it('returns null for empty or invalid date strings', () => {
        assert.equal(suggestedPlannedSignoffDate('', 90), null);
        assert.equal(suggestedPlannedSignoffDate('invalid-date', 90), null);
    });
});
