import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    calendarDatesDiffer,
    calendarDatesEqual,
    collectTimelineDates,
    daysPastPlannedEnd,
    daysUntilPlannedStart,
    elapsedWholeCalendarDays,
    formatDayCount,
    formatElapsedDayPhrase,
    formatSignedDayPhrase,
    inclusiveDurationDays,
    normalizeCalendarDate,
    phaseBarStyle,
    phasesHavePlannedDates,
    resolveSharedTimelineRange,
    shouldIncludeTodayInTimelineRange,
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

describe('normalizeCalendarDate', () => {
    it('normalizes equivalent date representations to the same calendar day', () => {
        assert.equal(normalizeCalendarDate('2026-09-01'), '2026-09-01');
        assert.equal(
            normalizeCalendarDate('2026-09-01 00:00:00'),
            '2026-09-01',
        );
        assert.equal(
            normalizeCalendarDate('2026-09-01T00:00:00'),
            '2026-09-01',
        );
        assert.equal(
            normalizeCalendarDate('2026-09-01T19:30:00Z', 'Asia/Dubai'),
            '2026-09-01',
        );
    });

    it('returns null for missing and invalid values', () => {
        assert.equal(normalizeCalendarDate(null), null);
        assert.equal(normalizeCalendarDate(undefined), null);
        assert.equal(normalizeCalendarDate(''), null);
        assert.equal(normalizeCalendarDate('   '), null);
        assert.equal(normalizeCalendarDate('not-a-date'), null);
    });
});

describe('milestone calendar comparisons', () => {
    it('treats equivalent representations as equal', () => {
        assert.equal(
            calendarDatesEqual('2026-09-01', '2026-09-01T00:00:00'),
            true,
        );
        assert.equal(
            calendarDatesDiffer('2026-09-01', '2026-09-01 00:00:00'),
            false,
        );
    });

    it('flags real calendar differences and ignores missing pairs', () => {
        assert.equal(calendarDatesDiffer('2026-09-01', '2026-09-03'), true);
        assert.equal(calendarDatesDiffer('2026-09-01', null), false);
        assert.equal(calendarDatesDiffer(null, '2026-09-01'), false);
        assert.equal(calendarDatesDiffer('bad', '2026-09-01'), false);
    });
});

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

    it('ignores representation differences for the same calendar day', () => {
        assert.equal(signedDayDelta('2026-09-01', '2026-09-01T08:00:00'), 0);
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

    it('does not include today for completed historical assignments', () => {
        const phases = [
            phase({
                planned_start_at: '2025-01-01',
                planned_end_at: '2025-01-10',
                actual_start_at: '2025-01-02',
                actual_end_at: '2025-01-11',
                status: 'completed',
            }),
        ];

        assert.equal(
            shouldIncludeTodayInTimelineRange(phases, 'plan_vs_actual'),
            false,
        );

        const dates = collectTimelineDates(phases, 'plan_vs_actual');
        const range = resolveSharedTimelineRange(dates, {
            todayIso: '2026-10-09',
            includeToday: false,
        });

        assert.deepEqual(range, { from: '2025-01-01', to: '2025-01-11' });
    });

    it('includes today only for open-ended active phases', () => {
        const phases = [
            phase({
                status: 'active',
                planned_start_at: '2026-09-01',
                planned_end_at: '2026-10-31',
                actual_start_at: '2026-09-03',
                actual_end_at: null,
            }),
        ];

        assert.equal(
            shouldIncludeTodayInTimelineRange(phases, 'plan_vs_actual'),
            true,
        );
        assert.equal(
            shouldIncludeTodayInTimelineRange(phases, 'planned_only'),
            false,
        );

        const dates = collectTimelineDates(phases, 'actual_only');
        const range = resolveSharedTimelineRange(dates, {
            todayIso: '2026-10-09',
            includeToday: true,
        });

        assert.deepEqual(range, { from: '2026-09-03', to: '2026-10-09' });
    });

    it('expands single-day ranges without inventing planned values', () => {
        const range = resolveSharedTimelineRange(['2026-09-01'], {
            includeToday: false,
        });

        assert.deepEqual(range, { from: '2026-08-31', to: '2026-09-02' });
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

    it('detects when planned-only mode has no planned data', () => {
        const phases = [
            phase({
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-09-03',
                actual_end_at: '2026-09-17',
            }),
        ];

        assert.equal(phasesHavePlannedDates(phases), false);
        assert.deepEqual(collectTimelineDates(phases, 'planned_only'), []);
        assert.equal(
            resolveSharedTimelineRange(
                collectTimelineDates(phases, 'planned_only'),
            ),
            null,
        );
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

describe('elapsed and deadline day helpers', () => {
    it('counts whole elapsed days without inventing a confirmed end', () => {
        assert.equal(elapsedWholeCalendarDays('2026-09-23', '2026-10-09'), 16);
        assert.equal(elapsedWholeCalendarDays('2026-10-09', '2026-10-09'), 0);
        assert.equal(elapsedWholeCalendarDays(null, '2026-10-09'), null);
        assert.equal(formatElapsedDayPhrase(0), 'Started today');
        assert.equal(formatElapsedDayPhrase(16), '16 days elapsed');
    });

    it('reports future planned starts and days past planned end only when meaningful', () => {
        assert.equal(daysUntilPlannedStart('2026-10-15', '2026-10-09'), 6);
        assert.equal(daysUntilPlannedStart('2026-10-09', '2026-10-09'), null);
        assert.equal(daysUntilPlannedStart('2026-10-01', '2026-10-09'), null);
        assert.equal(daysPastPlannedEnd('2026-10-01', '2026-10-09'), 8);
        assert.equal(daysPastPlannedEnd('2026-10-09', '2026-10-09'), null);
        assert.equal(daysPastPlannedEnd('2026-10-15', '2026-10-09'), null);
    });

    it('keeps single-day inclusive completed duration at 1 day', () => {
        assert.equal(inclusiveDurationDays('2026-09-23', '2026-09-23'), 1);
    });
});
