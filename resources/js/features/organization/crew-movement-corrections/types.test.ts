import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { correctionFieldLabel } from './types.ts';

describe('crew movement correction field labels', () => {
    it('labels current position_id as Position', () => {
        assert.equal(correctionFieldLabel('position_id'), 'Position');
    });

    it('keeps historical rank_id labelled as Rank', () => {
        assert.equal(correctionFieldLabel('rank_id'), 'Rank');
    });
});
