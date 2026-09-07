import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    RELIEF_DESK_QUICK_VIEWS,
    RELIEF_DESK_RESET_QUERY,
} from './relief-desk-query.ts';

describe('relief desk reset query', () => {
    it('explicitly clears every desk filter instead of relying on omitted keys', () => {
        const dirty: Record<string, string | number | boolean | null> = {
            view: 'relief',
            search: 'Ahmed',
            vessel_id: 12,
            rank_id: 4,
            client_id: 8,
            relief_status: 'no_relief',
            relief_risk: 'critical',
            planned_signoff_from: '2026-09-01',
            planned_signoff_to: '2026-09-30',
            horizon: 'all',
            focus: 'critical',
            page: 3,
            per_page: 50,
        };

        const merged = {
            ...dirty,
            ...RELIEF_DESK_RESET_QUERY,
        };

        assert.equal(merged.view, 'relief');
        assert.equal(merged.search, '');
        assert.equal(merged.vessel_id, null);
        assert.equal(merged.rank_id, null);
        assert.equal(merged.client_id, null);
        assert.equal(merged.relief_status, '');
        assert.equal(merged.relief_risk, '');
        assert.equal(merged.planned_signoff_from, '');
        assert.equal(merged.planned_signoff_to, '');
        assert.equal(merged.horizon, '30');
        assert.equal(merged.focus, '');
        assert.equal(merged.page, 1);
        assert.equal(merged.per_page, 50);
    });
});

describe('relief desk quick views', () => {
    it('labels the not_ready focus as Relief Not Ready', () => {
        const item = RELIEF_DESK_QUICK_VIEWS.find(
            (entry) => entry.key === 'not_ready',
        );

        assert.ok(item);
        assert.equal(item.label, 'Relief Not Ready');
        assert.notEqual(item.label, 'Not Ready');
    });
});
