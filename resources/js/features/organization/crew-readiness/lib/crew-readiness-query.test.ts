import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewReadinessSummary } from '../types.ts';
import {
    CREW_READINESS_QUICK_VIEWS,
    CREW_READINESS_RESET_QUERY,
    formatJoinTimeline,
} from './crew-readiness-query.ts';

describe('Crew Readiness Query & Helpers', () => {
    it('has valid reset query defaults', () => {
        assert.equal(CREW_READINESS_RESET_QUERY.search, null);
        assert.equal(CREW_READINESS_RESET_QUERY.vessel_id, null);
        assert.equal(CREW_READINESS_RESET_QUERY.position_id, null);
        assert.equal(CREW_READINESS_RESET_QUERY.readiness_status, 'all');
        assert.equal(CREW_READINESS_RESET_QUERY.source, 'all');
        assert.equal(CREW_READINESS_RESET_QUERY.window, '30');
        assert.equal(CREW_READINESS_RESET_QUERY.focus, null);
    });

    it('extracts correct summary values in quick views', () => {
        const summary: CrewReadinessSummary = {
            upcoming_crew: 25,
            ready: 12,
            attention: 8,
            not_ready: 3,
            joining_7: 6,
            no_checks: 2,
        };

        const keys = CREW_READINESS_QUICK_VIEWS.map((q) => q.key);
        assert.deepEqual(keys, [
            '',
            'ready',
            'attention',
            'not_ready',
            'joining_7',
            'no_checks',
        ]);

        const values = CREW_READINESS_QUICK_VIEWS.map((q) =>
            q.getValue(summary),
        );
        assert.deepEqual(values, [25, 12, 8, 3, 6, 2]);

        const readyView = CREW_READINESS_QUICK_VIEWS.find(
            (q) => q.key === 'ready',
        );
        assert.equal(readyView?.label, 'Ready');
        assert.equal(readyView?.getValue(summary), 12);

        const noChecksView = CREW_READINESS_QUICK_VIEWS.find(
            (q) => q.key === 'no_checks',
        );
        assert.equal(noChecksView?.label, 'No Checks Configured');
        assert.equal(noChecksView?.getValue(summary), 2);

        const joining7View = CREW_READINESS_QUICK_VIEWS.find(
            (q) => q.key === 'joining_7',
        );
        assert.equal(joining7View?.label, 'Joining in 7 Days');
        assert.equal(joining7View?.getValue(summary), 6);
    });

    it('formats join timeline correctly for past, present, and future join dates', () => {
        assert.equal(formatJoinTimeline(null, false), null);

        const overdueSingle = formatJoinTimeline(-1, true);
        assert.equal(overdueSingle?.label, '1 day overdue');
        assert.ok(overdueSingle?.tone.includes('rose'));

        const overdueMulti = formatJoinTimeline(-5, true);
        assert.equal(overdueMulti?.label, '5 days overdue');
        assert.ok(overdueMulti?.tone.includes('rose'));

        const today = formatJoinTimeline(0, false);
        assert.equal(today?.label, 'Joining today');
        assert.ok(today?.tone.includes('amber'));

        const tomorrow = formatJoinTimeline(1, false);
        assert.equal(tomorrow?.label, 'Joining tomorrow');

        const soon = formatJoinTimeline(4, false);
        assert.equal(soon?.label, 'In 4 days');
        assert.ok(soon?.tone.includes('sky'));

        const future = formatJoinTimeline(20, false);
        assert.equal(future?.label, 'In 20 days');
        assert.ok(future?.tone.includes('muted'));
    });
});
