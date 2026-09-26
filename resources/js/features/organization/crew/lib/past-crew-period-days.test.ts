import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { inclusivePeriodDays } from './past-crew-period-days.ts';

describe('inclusivePeriodDays', () => {
    it('calculates inclusive days from From/To dates', () => {
        assert.equal(inclusivePeriodDays('2026-09-01', '2026-09-23'), '23');
        assert.equal(inclusivePeriodDays('2026-09-01', '2026-09-01'), '1');
        assert.equal(inclusivePeriodDays('2026-09-01', '2026-09-04'), '4');
    });

    it('returns empty when To is open or dates are invalid', () => {
        assert.equal(inclusivePeriodDays('2026-09-01', ''), '');
        assert.equal(inclusivePeriodDays('2026-09-01', null), '');
        assert.equal(inclusivePeriodDays('', '2026-09-04'), '');
        assert.equal(inclusivePeriodDays('2026-09-05', '2026-09-01'), '');
    });
});
