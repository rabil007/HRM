import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { formatDisplayDate } from '../../../../lib/format-date.ts';
import type { PhaseTimelineItem } from '../types.ts';
import { buildPhaseDateLineSummary } from './phase-timeline-display.ts';
import {
    assignmentShowsP0ExpectedArrivalInPlannedOnly,
    shouldHideP0MobilisationActivationLines,
    shouldShowP0ExpectedArrival,
    shouldUseNeutralP0InProgressHeadline,
} from './phase-timeline-p0-expected-arrival.ts';

function p0Phase(
    overrides: Partial<PhaseTimelineItem> = {},
): PhaseTimelineItem {
    return {
        id: 1,
        sequence: 1,
        phase_code: 'p0',
        phase_label: 'Pre-Arrival',
        status: 'active',
        status_label: 'Active',
        planned_start_at: null,
        planned_end_at: null,
        actual_start_at: '2026-10-14',
        actual_end_at: null,
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
        ...overrides,
    };
}

const activeCurrentContext = {
    assignmentStatus: 'active',
    isCurrentPhase: true,
    plannedArrivalAt: '2026-10-17',
};

describe('P0 expected arrival display rules', () => {
    it('shows expected arrival for active current P0 in plan modes', () => {
        const phase = p0Phase();

        assert.equal(
            shouldShowP0ExpectedArrival(
                phase,
                'plan_vs_actual',
                activeCurrentContext,
            ),
            true,
        );
        assert.equal(
            shouldShowP0ExpectedArrival(
                phase,
                'planned_only',
                activeCurrentContext,
            ),
            true,
        );
        assert.equal(
            shouldShowP0ExpectedArrival(
                phase,
                'actual_only',
                activeCurrentContext,
            ),
            false,
        );
    });

    it('preserves fallback when expected arrival is missing', () => {
        const phase = p0Phase();

        assert.equal(
            shouldShowP0ExpectedArrival(phase, 'plan_vs_actual', {
                ...activeCurrentContext,
                plannedArrivalAt: null,
            }),
            false,
        );
    });

    it('does not show forecast on completed historical P0', () => {
        const phase = p0Phase({
            status: 'completed',
            status_label: 'Completed',
            actual_end_at: '2026-10-16',
        });

        assert.equal(
            shouldShowP0ExpectedArrival(phase, 'plan_vs_actual', {
                ...activeCurrentContext,
                isCurrentPhase: false,
            }),
            false,
        );
    });

    it('does not show forecast on non-P0 phases', () => {
        const phase = p0Phase({
            phase_code: 'p1a',
            phase_label: 'Pre-Joining Standby',
        });

        assert.equal(
            shouldShowP0ExpectedArrival(
                phase,
                'plan_vs_actual',
                activeCurrentContext,
            ),
            false,
        );
    });

    it('hides mobilisation activation lines only for active current P0', () => {
        const phase = p0Phase();

        assert.equal(
            shouldHideP0MobilisationActivationLines(
                phase,
                activeCurrentContext,
            ),
            true,
        );
        assert.equal(
            shouldHideP0MobilisationActivationLines(phase, {
                ...activeCurrentContext,
                isCurrentPhase: false,
            }),
            false,
        );
        assert.equal(
            shouldHideP0MobilisationActivationLines(
                p0Phase({ status: 'completed', actual_end_at: '2026-10-16' }),
                activeCurrentContext,
            ),
            false,
        );
    });

    it('uses neutral in-progress headline when forecast replaces missing phase plan', () => {
        const phase = p0Phase();

        assert.equal(
            shouldUseNeutralP0InProgressHeadline(phase, activeCurrentContext),
            true,
        );
    });

    it('keeps variance headline when phase already has planned dates', () => {
        const phase = p0Phase({
            planned_start_at: '2026-10-14',
            planned_end_at: '2026-10-20',
        });

        assert.equal(
            shouldUseNeutralP0InProgressHeadline(phase, activeCurrentContext),
            false,
        );
    });

    it('formats expected arrival with the supplied company date formatter', () => {
        const summary = buildPhaseDateLineSummary(
            p0Phase(),
            'planned_only',
            '2026-10-14',
            {
                formatDate: (value) => formatDisplayDate(value, 'M d, Y'),
                p0ExpectedArrivalContext: activeCurrentContext,
            },
        );

        assert.equal(summary.expectedArrivalLabel, 'Oct 17, 2026');
    });

    it('treats planned-only mode as non-empty when current P0 has forecast', () => {
        assert.equal(
            assignmentShowsP0ExpectedArrivalInPlannedOnly({
                status: 'active',
                planned_arrival_at: '2026-10-17',
                current_phase: { id: 1, code: 'p0' },
                phase_timeline: [p0Phase()],
            }),
            true,
        );
        assert.equal(
            assignmentShowsP0ExpectedArrivalInPlannedOnly({
                status: 'active',
                planned_arrival_at: '2026-10-17',
                current_phase: { id: 2, code: 'p1a' },
                phase_timeline: [
                    p0Phase({
                        id: 1,
                        status: 'completed',
                        actual_end_at: '2026-10-16',
                    }),
                    p0Phase({
                        id: 2,
                        phase_code: 'p1a',
                        phase_label: 'Pre-Joining Standby',
                    }),
                ],
            }),
            false,
        );
    });
});
