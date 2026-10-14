import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentMilestoneRows } from './assignment-milestone-display.ts';

describe('buildAssignmentMilestoneRows', () => {
    it('excludes assignment started and closed lifecycle rows', () => {
        const rows = buildAssignmentMilestoneRows({
            planned_arrival_at: '2026-10-18',
            actual_arrival_at: null,
            planned_join_at: '2026-10-20',
            actual_join_at: null,
            planned_travel_at: null,
            planned_signoff_at: null,
            actual_disembarkation_at: null,
        });

        const labels = rows.map((row) => row.label);

        assert.deepEqual(labels, [
            'Arrival',
            'Vessel Join',
            'Travel',
            'Sign-Off',
        ]);
        assert.ok(!labels.includes('Started'));
        assert.ok(!labels.includes('Closed'));
    });
});
