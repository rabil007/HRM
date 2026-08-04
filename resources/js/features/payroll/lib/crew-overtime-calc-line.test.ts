import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildOvertimeCalcLine } from './crew-overtime-calc-line.ts';

describe('crew overtime calculation display', () => {
    it('shows hours × (hour rate × 1.25) when hour rate is available', () => {
        assert.equal(
            buildOvertimeCalcLine(10, 22.71, 18.16),
            '10 × (18.16 × 1.25)',
        );
    });

    it('falls back to hours × overtime hourly rate', () => {
        assert.equal(buildOvertimeCalcLine(10, 22.71), '10 × 22.71');
    });

    it('returns null when hours are zero', () => {
        assert.equal(buildOvertimeCalcLine(0, 22.71, 18.16), null);
    });
});
