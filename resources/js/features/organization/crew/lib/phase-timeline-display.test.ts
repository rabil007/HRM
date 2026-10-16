import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    buildOperationalPhaseLines,
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

    it('labels allocated standby service separately from the movement span', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                phase_code: 'p1a',
                phase_label: 'Pre-Joining Standby',
                status: 'completed',
                status_label: 'Completed',
                actual_start_at: '2026-10-01',
                actual_end_at: '2026-10-05',
                allocated_service_days: 4,
            }),
            'actual_only',
            '2026-10-15',
        );

        assert.ok(
            summary.durationParts.some((part) =>
                part.includes('Standby service: 4 days'),
            ),
        );
        assert.ok(
            summary.durationParts.some((part) =>
                part.includes('Movement span: 5 days'),
            ),
        );
        assert.equal(
            summary.durationParts.some((part) => /standby days/i.test(part)),
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

    it('hides P0 mobilisation activation lines for active current P0', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-10-14',
            }),
            'plan_vs_actual',
            '2026-10-14',
            {
                p0ExpectedArrivalContext: {
                    assignmentStatus: 'active',
                    isCurrentPhase: true,
                    plannedArrivalAt: '2026-10-17',
                },
            },
        );

        assert.equal(summary.showActual, false);
        assert.equal(summary.durationParts.length, 0);
    });

    it('shows assignment expected arrival on current P0 without phase planned dates', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-10-14',
            }),
            'plan_vs_actual',
            '2026-10-14',
            {
                timeZone: 'Asia/Dubai',
                formatDate: (value) =>
                    value ? `FMT:${String(value).slice(0, 10)}` : '—',
                p0ExpectedArrivalContext: {
                    assignmentStatus: 'active',
                    isCurrentPhase: true,
                    plannedArrivalAt: '2026-10-17',
                },
            },
        );

        assert.equal(summary.showExpectedArrival, true);
        assert.equal(summary.expectedArrivalLabel, 'FMT:2026-10-17');
    });

    it('hides expected arrival in Actual Only mode', () => {
        const summary = buildPhaseDateLineSummary(
            phase({
                phase_code: 'p0',
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-10-14',
            }),
            'actual_only',
            '2026-10-14',
            {
                p0ExpectedArrivalContext: {
                    assignmentStatus: 'active',
                    isCurrentPhase: true,
                    plannedArrivalAt: '2026-10-17',
                },
            },
        );

        assert.equal(summary.showExpectedArrival, false);
        assert.equal(summary.showPlanned, false);
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

describe('buildOperationalPhaseLines', () => {
    it('shows an active on-vessel planned sign-off without treating it as disembarkation', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p2',
                phase_label: 'On Vessel',
                actual_start_at: '2026-10-10',
                planned_end_at: null,
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                timeZone: 'Asia/Dubai',
                plannedSignoffAt: '2026-10-25',
            },
        );

        assert.deepEqual(
            lines.find((line) => line.label === 'Joined Vessel'),
            { label: 'Joined Vessel', value: '2026-10-10', planned: false },
        );
        assert.deepEqual(
            lines.find((line) => line.label === 'Planned Sign-Off'),
            { label: 'Planned Sign-Off', value: '2026-10-25', planned: true },
        );
        assert.equal(
            lines.some((line) => line.label === 'Actual Movement'),
            false,
        );
        assert.equal(
            lines.find((line) => line.label === 'Elapsed')?.value,
            '6 days elapsed',
        );
    });

    it('does not invent a planned sign-off', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p2',
                phase_label: 'On Vessel',
                actual_start_at: '2026-10-10',
                planned_end_at: null,
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                plannedSignoffAt: null,
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Planned Sign-Off')?.value,
            'Sign-Off Not Planned',
        );
    });

    it('keeps allocated standby service days separate from the movement span', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p1a',
                phase_label: 'Pre-Joining Standby',
                status: 'completed',
                actual_start_at: '2026-10-02',
                actual_end_at: '2026-10-10',
                allocated_service_days: 8,
            }),
            {
                isCurrent: false,
                todayIso: '2026-10-16',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Actual Movement')?.value,
            '2026-10-02 → 2026-10-10',
        );
        assert.equal(
            lines.find((line) => line.label === 'Service')?.value,
            'Standby service: 8 days',
        );
        assert.equal(
            lines.some((line) => line.value.includes('calendar span')),
            false,
        );
    });

    it('treats modern home as redeployment rather than a vessel join', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p4',
                phase_label: 'Home / Redeployment',
                actual_start_at: '2026-10-14',
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                plannedSignoffAt: '2026-10-25',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Returned Home')?.value,
            '2026-10-14',
        );
        assert.equal(
            lines.find((line) => line.label === 'Home / Redeployment')?.value,
            'Awaiting closure',
        );
        assert.equal(
            lines.some((line) => line.label === 'Planned Sign-Off'),
            false,
        );
        assert.equal(
            lines.some((line) => line.label === 'Joined Vessel'),
            false,
        );
    });

    it('does not call a draft planned P0 active or count elapsed days', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                status: 'planned',
                status_label: 'Planned',
                planned_start_at: '2026-10-20',
                planned_end_at: null,
                actual_start_at: null,
                actual_end_at: null,
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                assignmentStatus: 'draft',
                plannedArrivalAt: '2026-10-20',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Expected Arrival')?.value,
            '2026-10-20',
        );
        assert.equal(
            lines.find((line) => line.label === 'Expected Arrival')?.planned,
            true,
        );
        assert.equal(
            lines.find((line) => line.label === 'Status')?.value,
            'Not Started',
        );
        assert.equal(
            lines.some((line) => line.value === 'Active'),
            false,
        );
        assert.equal(
            lines.some((line) => line.label === 'Elapsed'),
            false,
        );
    });

    it('keeps an active P0 awaiting arrival active without elapsed days', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                status: 'active',
                status_label: 'Active',
                actual_start_at: '2026-10-14',
                actual_end_at: null,
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                assignmentStatus: 'active',
                plannedArrivalAt: '2026-10-20',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Status')?.value,
            'Active',
        );
        assert.equal(
            lines.find((line) => line.label === 'Expected Arrival')?.value,
            '2026-10-20',
        );
        assert.equal(
            lines.some((line) => line.label === 'Elapsed'),
            false,
        );
    });

    it('shows the planned sign-off on an active P2 without calling it an actual movement', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p2',
                phase_label: 'On Vessel',
                status: 'active',
                actual_start_at: '2026-10-10',
                actual_end_at: null,
            }),
            {
                isCurrent: true,
                todayIso: '2026-10-16',
                assignmentStatus: 'active',
                plannedSignoffAt: '2026-10-25',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Status')?.value,
            'Active',
        );
        assert.equal(
            lines.find((line) => line.label === 'Planned Sign-Off')?.planned,
            true,
        );
        assert.equal(
            lines.some((line) => line.label === 'Actual Movement'),
            false,
        );
    });

    it('keeps a completed historical phase completed', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p0',
                phase_label: 'Pre-Arrival',
                status: 'completed',
                status_label: 'Completed',
                actual_start_at: '2026-10-01',
                actual_end_at: '2026-10-05',
            }),
            {
                isCurrent: false,
                todayIso: '2026-10-16',
                assignmentStatus: 'active',
                plannedArrivalAt: '2026-10-20',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Actual Movement')?.value,
            '2026-10-01 → 2026-10-05',
        );
        assert.equal(
            lines.some((line) => line.label === 'Status'),
            false,
        );
        assert.equal(
            lines.some((line) => line.value === 'Active'),
            false,
        );
        assert.equal(
            lines.some((line) => line.label === 'Expected Arrival'),
            false,
        );
    });

    it('does not mark a future scheduled phase active before it executes', () => {
        const lines = buildOperationalPhaseLines(
            phase({
                phase_code: 'p1a',
                phase_label: 'Pre-Joining Standby',
                status: 'planned',
                status_label: 'Planned',
                planned_start_at: '2026-10-20',
                planned_end_at: null,
                actual_start_at: null,
                actual_end_at: null,
            }),
            {
                isCurrent: false,
                todayIso: '2026-10-16',
                assignmentStatus: 'active',
                plannedJoinAt: '2026-10-22',
            },
        );

        assert.equal(
            lines.find((line) => line.label === 'Status')?.value,
            'Planned',
        );
        assert.equal(
            lines.find((line) => line.label === 'Expected Vessel Join')
                ?.planned,
            true,
        );
        assert.equal(
            lines.some((line) => line.value === 'Active'),
            false,
        );
        assert.equal(
            lines.some((line) => line.label === 'Elapsed'),
            false,
        );
        assert.equal(
            lines.some((line) => line.label === 'Arrived'),
            false,
        );
    });
});
