import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    keyOperationalDateDetail,
    keyOperationalDateKindLabel,
    operatorAttentionBadgeVariant,
} from './operator-presentation.ts';

describe('operator attention badge tone', () => {
    it('maps critical, warning, information, and clear tones', () => {
        assert.equal(operatorAttentionBadgeVariant('critical'), 'destructive');
        assert.equal(operatorAttentionBadgeVariant('warning'), 'warning');
        assert.equal(operatorAttentionBadgeVariant('info'), 'info');
        assert.equal(operatorAttentionBadgeVariant('clear'), 'outline');
    });
});

describe('key operational date kind', () => {
    it('distinguishes planned, recorded, and actual dates', () => {
        assert.equal(keyOperationalDateKindLabel('planned'), 'Planned');
        assert.equal(keyOperationalDateKindLabel('recorded'), 'Recorded');
        assert.equal(keyOperationalDateKindLabel('actual'), 'Actual');
        assert.match(
            keyOperationalDateDetail('planned'),
            /not an actual movement/i,
        );
        assert.match(
            keyOperationalDateDetail('recorded'),
            /not an actual crew movement/i,
        );
        assert.match(keyOperationalDateDetail('actual'), /operational date/i);
    });
});
