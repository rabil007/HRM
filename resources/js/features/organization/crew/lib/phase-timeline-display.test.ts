import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    buildPhaseDateLineSummary,
    durationPartsLeakPlannedOverdue,
} from './phase-timeline-display.ts';

function phase(overrides: Partial<PhaseTimelineItem> = {}): PhaseTimelineItem {
    return {
        id: 1,
        sequence: 1,
        phase_code: 'p4',
        phase_label: 'On Vessel',
        status: 'active',
        status_label: 'Active',
        planned_start_at: '2026-09-01',
        planned_end_at: '2026-09-15',
        actual_start_at: '2026-09-03',
        actual_end_at: null,
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

describe('buildPhaseDateLineSummary modes', () => {
    it('shows planned, actual, elapsed, overdue, and variance context in Plan vs Actual', () => {
        const summary = buildPhaseDateLineSummary(
            phase(),
            'plan_vs_actual',
            '2026-10-09',
            { timeZone: 'Asia/Dubai' },
        );

        assert.equal(summary.showPlanned, true);
        assert.equal(summary.showActual, true);
        assert.equal(summary.showVariance, true);
        assert.ok(summary.durationParts.some((part) => /elapsed/i.test(part)));
        assert.ok(
            summary.durationParts.some((part) =>
                /past planned end/i.test(part),
            ),
        );
        assert.ok(
            summary.durationParts.some((part) => /^Planned\b/.test(part)),
        );
    });

    it('keeps Actual Only free of planned windows and planned overdue', () => {
        const summary = buildPhaseDateLineSummary(
            phase(),
            'actual_only',
            '2026-10-09',
            { timeZone: 'Asia/Dubai' },
        );

        assert.equal(summary.showPlanned, false);
        assert.equal(summary.showActual, true);
        assert.equal(summary.showVariance, false);
        assert.equal(
            durationPartsLeakPlannedOverdue(summary.durationParts),
            false,
        );
        assert.ok(summary.durationParts.some((part) => /elapsed/i.test(part)));
        assert.equal(
            summary.durationParts.some((part) =>
                /past planned end/i.test(part),
            ),
            false,
        );
        assert.equal(
            summary.durationParts.some((part) => /^Planned\b/.test(part)),
            false,
        );
    });

    it('shows Planned Only dates, planned duration, and days until start', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                status: 'planned',
                status_label: 'Planned',
                actual_start_at: null,
                actual_end_at: null,
                planned_start_at: '2026-10-15',
                planned_end_at: '2026-10-30',
            }),
            'planned_only',
            '2026-10-09',
            { timeZone: 'Asia/Dubai' },
        );

        assert.equal(summary.showPlanned, true);
        assert.equal(summary.showActual, false);
        assert.equal(summary.showVariance, false);
        assert.ok(
            summary.durationParts.some((part) => /^Planned\b/.test(part)),
        );
        assert.ok(
            summary.durationParts.some((part) =>
                /until planned start/i.test(part),
            ),
        );
        assert.equal(
            summary.durationParts.some((part) => /elapsed/i.test(part)),
            false,
        );
    });

    it('reports inclusive completed duration for finished phases', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                status: 'completed',
                status_label: 'Completed',
                actual_start_at: '2026-09-03',
                actual_end_at: '2026-09-17',
            }),
            'actual_only',
            '2026-10-09',
        );

        assert.ok(
            summary.durationParts.some((part) =>
                /15 days completed/.test(part),
            ),
        );
        assert.equal(summary.actualDurationLabel, '15 days');
        assert.equal(
            durationPartsLeakPlannedOverdue(summary.durationParts),
            false,
        );
    });

    it('keeps historical completed assignments free of elapsed inventing today as end', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                status: 'completed',
                status_label: 'Completed',
                planned_start_at: '2025-01-01',
                planned_end_at: '2025-01-10',
                actual_start_at: '2025-01-02',
                actual_end_at: '2025-01-11',
            }),
            'plan_vs_actual',
            '2026-10-09',
        );

        assert.equal(
            summary.durationParts.some((part) => /elapsed/i.test(part)),
            false,
        );
        assert.ok(
            summary.durationParts.some((part) => /completed/i.test(part)),
        );
        assert.notEqual(summary.actualEndLabel, 'In progress');
    });

    it('updates elapsed wording when the company-local today advances', () => {
        const active = phase({
            actual_start_at: '2026-10-08',
            planned_end_at: '2026-12-01',
        });

        const beforeMidnight = buildPhaseDateLineSummary(
            active,
            'actual_only',
            '2026-10-08',
            { timeZone: 'Asia/Dubai' },
        );
        const afterMidnight = buildPhaseDateLineSummary(
            active,
            'actual_only',
            '2026-10-09',
            { timeZone: 'Asia/Dubai' },
        );

        assert.ok(beforeMidnight.durationParts.includes('Started today'));
        assert.ok(afterMidnight.durationParts.includes('1 day elapsed'));
        assert.equal(
            durationPartsLeakPlannedOverdue(afterMidnight.durationParts),
            false,
        );
    });
});
