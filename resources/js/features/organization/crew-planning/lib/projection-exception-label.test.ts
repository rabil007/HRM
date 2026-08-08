import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { projectionExceptionLabel } from './projection-band.ts';

describe('projectionExceptionLabel', () => {
    it('maps current_gap to Manning Shortfall', () => {
        assert.equal(
            projectionExceptionLabel('current_gap'),
            'Manning Shortfall',
        );
    });

    it('maps future_gap to Future Shortfall', () => {
        assert.equal(
            projectionExceptionLabel('future_gap'),
            'Future Shortfall',
        );
    });

    it('maps overlap to Relief Overlap', () => {
        assert.equal(projectionExceptionLabel('overlap'), 'Relief Overlap');
    });

    it('returns null for covered or covered_by_incoming', () => {
        assert.equal(projectionExceptionLabel('covered'), null);
        assert.equal(projectionExceptionLabel('covered_by_incoming'), null);
    });
});
