import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { hireDateCalendarValueChanged } from './hire-date-change-preview.ts';

describe('hireDateCalendarValueChanged', () => {
    it('returns false when calendar dates match', () => {
        assert.equal(
            hireDateCalendarValueChanged('2026-02-02', '2026-02-02'),
            false,
        );
        assert.equal(hireDateCalendarValueChanged(null, ''), false);
    });

    it('returns true when hire date changes including empty values', () => {
        assert.equal(
            hireDateCalendarValueChanged('2026-02-02', '2026-04-01'),
            true,
        );
        assert.equal(hireDateCalendarValueChanged('2026-02-02', null), true);
        assert.equal(hireDateCalendarValueChanged(null, '2026-04-01'), true);
    });
});
