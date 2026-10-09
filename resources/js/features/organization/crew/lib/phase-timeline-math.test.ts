import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    collectTimelineDates,
    formatDayCount,
    formatSignedDayPhrase,
    inclusiveDurationDays,
    phaseBarStyle,
    resolveSharedTimelineRange,
    signedDayDelta,
} from './phase-timeline-math.ts';

function phase(overrides: Partial<PhaseTimelineItem> = {}): PhaseTimelineItem {
    return {
        id: 1,
        sequence: 1,
        phase_code: 'p4',
        phase_label: 'On Vessel',
        status: 'completed',
        status_label: 'Completed',
        planned_start_at: '2026-09-01',
        planned_end_at: '2026-09-15',
        actual_start_at: '2026-09-03',
        actual_end_at: '2026-09-17',
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

describe('inclusiveDurationDays', () => {
    it('counts inclusive calendar days', () => {
        assert.equal(inclusiveDurationDays('2026-09-01', '2026-09-15'), 15);
    });

    it('returns 1 for same-day periods', () => {
        assert.equal(inclusiveDurationDays('2026-09-01', '2026-09-01'), 1);
    });

    it('returns null when either date is missing or inverted', () => {
        assert.equal(inclusiveDurationDays(null, '2026-09-15'), null);
        assert.equal(inclusiveDurationDays('2026-09-01', null), null);
        assert.equal(inclusiveDurationDays('2026-09-15', '2026-09-01'), null);
    });
});

describe('signedDayDelta', () => {
    it('reports late, early, and on-time deltas', () => {
        assert.equal(signedDayDelta('2026-09-01', '2026-09-03'), 2);
        assert.equal(signedDayDelta('2026-09-03', '2026-09-01'), -2);
        assert.equal(signedDayDelta('2026-09-01', '2026-09-01'), 0);
        assert.equal(signedDayDelta(null, '2026-09-01'), null);
    });
});

describe('shared timeline range and bars', () => {
    it('builds a shared range from all modes without inventing planned values', () => {
        const phases = [
            phase(),
            phase({
                id: 2,
                sequence: 2,
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-09-18',
                actual_end_at: '2026-09-20',
            }),
        ];

        const planVsActual = collectTimelineDates(phases, 'plan_vs_actual');
        assert.deepEqual([...planVsActual].sort(), [
            '2026-09-01',
            '2026-09-03',
            '2026-09-15',
            '2026-09-17',
            '2026-09-18',
            '2026-09-20',
        ]);

        const plannedOnly = collectTimelineDates(phases, 'planned_only');
        assert.deepEqual([...plannedOnly].sort(), ['2026-09-01', '2026-09-15']);

        const actualOnly = collectTimelineDates(phases, 'actual_only');
        assert.deepEqual([...actualOnly].sort(), [
            '2026-09-03',
            '2026-09-17',
            '2026-09-18',
            '2026-09-20',
        ]);
    });

    it('positions overlapping planned and actual bars on one calendar scale', () => {
        const range = resolveSharedTimelineRange([
            '2026-09-01',
            '2026-09-03',
            '2026-09-15',
            '2026-09-17',
        ]);

        assert.ok(range);

        const planned = phaseBarStyle(
            '2026-09-01',
            '2026-09-15',
            range.from,
            range.to,
        );
        const actual = phaseBarStyle(
            '2026-09-03',
            '2026-09-17',
            range.from,
            range.to,
        );

        assert.notDeepEqual(planned, { display: 'none' });
        assert.notDeepEqual(actual, { display: 'none' });

        if ('left' in planned && 'left' in actual) {
            assert.ok(parseFloat(planned.left) < parseFloat(actual.left));
            assert.ok(parseFloat(planned.width) > 0);
            assert.ok(parseFloat(actual.width) > 0);
        }
    });

    it('supports open-ended visual bars without requiring a confirmed end', () => {
        const range = resolveSharedTimelineRange(['2026-09-01', '2026-09-10']);
        assert.ok(range);

        const open = phaseBarStyle('2026-09-01', null, range.from, range.to, {
            openEndedVisualEnd: '2026-09-10',
        });

        assert.notDeepEqual(open, { display: 'none' });
    });
});

describe('phrase helpers', () => {
    it('formats day counts and signed phrases', () => {
        assert.equal(formatDayCount(1), '1 day');
        assert.equal(formatDayCount(2), '2 days');
        assert.equal(formatDayCount(null), '—');
        assert.equal(formatSignedDayPhrase(0, 'early', 'late'), 'on time');
        assert.equal(formatSignedDayPhrase(2, 'early', 'late'), '2 days late');
        assert.equal(formatSignedDayPhrase(-1, 'early', 'late'), '1 day early');
    });
});
