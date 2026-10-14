import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentLifecycleLabels } from './assignment-lifecycle-display.ts';

const fmt = (value: string | null | undefined): string =>
    value ? `D:${value.slice(0, 10)}` : '—';

describe('buildAssignmentLifecycleLabels', () => {
    it('uses started_at for assignment started', () => {
        const labels = buildAssignmentLifecycleLabels(
            {
                status: 'active',
                started_at: '2026-10-14T08:00:00+04:00',
                closed_at: null,
            },
            fmt,
        );

        assert.equal(labels.started, 'D:2026-10-14');
        assert.equal(labels.ended, 'Ongoing');
    });

    it('uses closed_at for completed assignments', () => {
        const labels = buildAssignmentLifecycleLabels(
            {
                status: 'completed',
                started_at: '2026-10-14',
                closed_at: '2026-10-30',
            },
            fmt,
        );

        assert.equal(labels.ended, 'D:2026-10-30');
    });

    it('shows Ongoing for active assignments without closed_at', () => {
        const labels = buildAssignmentLifecycleLabels(
            {
                status: 'active',
                started_at: '2026-10-14',
                closed_at: null,
            },
            fmt,
        );

        assert.equal(labels.ended, 'Ongoing');
    });

    it('does not fabricate start dates for draft assignments', () => {
        const labels = buildAssignmentLifecycleLabels(
            {
                status: 'draft',
                started_at: null,
                closed_at: null,
            },
            fmt,
        );

        assert.equal(labels.started, 'Not started');
        assert.equal(labels.ended, 'Not started');
    });

    it('shows Not recorded when terminal assignment lacks closed_at', () => {
        const labels = buildAssignmentLifecycleLabels(
            {
                status: 'cancelled',
                started_at: '2026-10-01',
                closed_at: null,
            },
            fmt,
        );

        assert.equal(labels.ended, 'Not recorded');
    });
});
