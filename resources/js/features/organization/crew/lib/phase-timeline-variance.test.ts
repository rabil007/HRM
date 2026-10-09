import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import { summarizePhaseVariance } from './phase-timeline-variance.ts';

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
        actual_start_at: '2026-09-01',
        actual_end_at: '2026-09-15',
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

describe('summarizePhaseVariance', () => {
    it('reports on-time completed movements', () => {
        const summary = summarizePhaseVariance(phase());

        assert.equal(summary.headline, 'On time');
        assert.equal(summary.tone, 'success');
        assert.equal(summary.hasDeviation, false);
        assert.equal(summary.startVarianceDays, 0);
        assert.equal(summary.endVarianceDays, 0);
        assert.equal(summary.durationVarianceDays, 0);
        assert.equal(summary.plannedDurationDays, 15);
        assert.equal(summary.actualDurationDays, 15);
    });

    it('reports delayed start and delayed completion separately', () => {
        const summary = summarizePhaseVariance(
            phase({
                actual_start_at: '2026-09-03',
                actual_end_at: '2026-09-17',
            }),
        );

        assert.equal(summary.hasDeviation, true);
        assert.equal(summary.tone, 'warning');
        assert.equal(summary.startVarianceDays, 2);
        assert.equal(summary.endVarianceDays, 2);
        assert.equal(summary.durationVarianceDays, 0);
        assert.ok(summary.details.includes('Started 2 days late'));
        assert.ok(summary.details.includes('Completed 2 days late'));
    });

    it('reports early start and early completion', () => {
        const summary = summarizePhaseVariance(
            phase({
                actual_start_at: '2026-08-30',
                actual_end_at: '2026-09-13',
            }),
        );

        assert.equal(summary.startVarianceDays, -2);
        assert.equal(summary.endVarianceDays, -2);
        assert.ok(summary.details.includes('Started 2 days early'));
        assert.ok(summary.details.includes('Completed 2 days early'));
    });

    it('reports duration difference when ends shift unequally', () => {
        const summary = summarizePhaseVariance(
            phase({
                actual_start_at: '2026-09-01',
                actual_end_at: '2026-09-20',
            }),
        );

        assert.equal(summary.durationVarianceDays, 5);
        assert.ok(summary.details.includes('Duration 5 days longer'));
    });

    it('does not treat today as a confirmed completion for active phases', () => {
        const summary = summarizePhaseVariance(
            phase({
                status: 'active',
                status_label: 'Active',
                actual_start_at: '2026-09-03',
                actual_end_at: null,
            }),
        );

        assert.equal(summary.isInProgress, true);
        assert.equal(summary.hasConfirmedActualEnd, false);
        assert.equal(summary.endVarianceDays, null);
        assert.equal(summary.actualDurationDays, null);
        assert.equal(summary.durationVarianceDays, null);
        assert.equal(summary.startVarianceDays, 2);
        assert.equal(summary.tone, 'warning');
        assert.ok(summary.details.includes('Started 2 days late'));
        assert.ok(
            !summary.details.some((detail) => detail.startsWith('Completed')),
        );
    });

    it('handles missing planned dates without inventing values', () => {
        const summary = summarizePhaseVariance(
            phase({
                planned_start_at: null,
                planned_end_at: null,
                actual_start_at: '2026-09-03',
                actual_end_at: '2026-09-17',
            }),
        );

        assert.equal(summary.hasPlannedDates, false);
        assert.equal(summary.startVarianceDays, null);
        assert.equal(summary.endVarianceDays, null);
        assert.equal(
            summary.headline,
            'Completed · variance unavailable (no planned dates)',
        );
        assert.equal(summary.tone, 'success');
    });

    it('keeps plan vs actual usable when planned dates are incomplete', () => {
        const summary = summarizePhaseVariance(
            phase({
                planned_start_at: null,
                planned_end_at: null,
                status: 'active',
                status_label: 'Active',
                actual_start_at: '2026-09-03',
                actual_end_at: null,
            }),
        );

        assert.equal(summary.isInProgress, true);
        assert.equal(summary.hasActualDates, true);
        assert.equal(
            summary.headline,
            'In progress · variance unavailable (no planned dates)',
        );
        assert.equal(summary.startVarianceDays, null);
        assert.equal(summary.endVarianceDays, null);
    });

    it('normalizes equivalent date representations before variance math', () => {
        const summary = summarizePhaseVariance(
            phase({
                planned_start_at: '2026-09-01T00:00:00',
                planned_end_at: '2026-09-15 00:00:00',
                actual_start_at: '2026-09-01',
                actual_end_at: '2026-09-15',
            }),
        );

        assert.equal(summary.hasDeviation, false);
        assert.equal(summary.headline, 'On time');
        assert.equal(summary.startVarianceDays, 0);
        assert.equal(summary.endVarianceDays, 0);
    });

    it('marks not-started planned phases', () => {
        const summary = summarizePhaseVariance(
            phase({
                status: 'planned',
                status_label: 'Planned',
                actual_start_at: null,
                actual_end_at: null,
            }),
        );

        assert.equal(summary.isNotStarted, true);
        assert.equal(summary.headline, 'Not started');
        assert.equal(summary.tone, 'pending');
    });

    it('supports consecutive overlapping phase date sets independently', () => {
        const first = summarizePhaseVariance(
            phase({
                id: 1,
                planned_start_at: '2026-09-01',
                planned_end_at: '2026-09-10',
                actual_start_at: '2026-09-01',
                actual_end_at: '2026-09-12',
            }),
        );
        const second = summarizePhaseVariance(
            phase({
                id: 2,
                phase_code: 'p5',
                planned_start_at: '2026-09-11',
                planned_end_at: '2026-09-14',
                actual_start_at: '2026-09-12',
                actual_end_at: '2026-09-14',
            }),
        );

        assert.equal(first.endVarianceDays, 2);
        assert.equal(second.startVarianceDays, 1);
        assert.equal(second.endVarianceDays, 0);
    });
});
