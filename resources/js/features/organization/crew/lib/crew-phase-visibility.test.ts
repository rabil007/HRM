import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    LEGACY_CREW_PHASES,
    legacyPhaseContextLabel,
    NORMAL_PHASE_PROGRESS_STEPS,
    NORMAL_VISIBLE_CREW_PHASES,
    normalProgressStepState,
    normalVisiblePhaseFilterOptions,
    resolveActiveLegacyPhaseContext,
} from './crew-phase-visibility.ts';

function timelineItem(
    phaseCode: string,
    status: 'active' | 'completed',
): PhaseTimelineItem {
    return {
        id: 1,
        sequence: 1,
        phase_code: phaseCode,
        phase_label: phaseCode,
        status,
        status_label: status,
        planned_start_at: null,
        planned_end_at: null,
        actual_start_at: '2026-09-01',
        actual_end_at: status === 'completed' ? '2026-09-02' : null,
        details: null,
        remarks: null,
        has_pending_correction: false,
        has_approved_correction: false,
    };
}

describe('crew phase visibility', () => {
    it('keeps a central normal visible phase list without legacy phases', () => {
        assert.deepEqual(NORMAL_VISIBLE_CREW_PHASES, [
            'p0',
            'p2a',
            'p2b',
            'p4',
            'p5',
            'p6',
        ]);
        assert.deepEqual(LEGACY_CREW_PHASES, ['p1', 'p3']);
    });

    it('does not include legacy placeholders in the normal progress path', () => {
        const stepKeys = NORMAL_PHASE_PROGRESS_STEPS.map((step) => step.key);

        assert.deepEqual(stepKeys, ['p0', 'p2', 'p4', 'p5', 'p6']);
        assert.equal(stepKeys.includes('p1'), false);
        assert.equal(stepKeys.includes('p3'), false);
    });

    it('excludes legacy phases from normal current phase filter options', () => {
        const options = normalVisiblePhaseFilterOptions();

        assert.equal(
            options.some((option) => option.value === 'p1'),
            false,
        );
        assert.equal(
            options.some((option) => option.value === 'p3'),
            false,
        );
        assert.equal(
            options.some((option) => option.value === 'p2a'),
            true,
        );
    });

    it('formats legacy context labels for active legacy phases', () => {
        assert.equal(
            legacyPhaseContextLabel('p1'),
            'Legacy phase · P1 Travel In',
        );
        assert.equal(
            legacyPhaseContextLabel('p3'),
            'Legacy phase · P3 Ready to Join',
        );
        assert.equal(legacyPhaseContextLabel('p2a'), null);
    });

    it('does not mark prior normal phases completed for legacy current phases without timeline evidence', () => {
        const p1States = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p1', []),
        );
        assert.deepEqual(p1States, [
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const p3States = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p3', []),
        );
        assert.deepEqual(p3States, [
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);
    });

    it('marks only timeline-backed completion for normal and legacy assignments', () => {
        const p0Only = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p0', []),
        );
        assert.deepEqual(p0Only, [
            'current',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const p2aAfterCompletedP0 = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p2a', [
                timelineItem('p0', 'completed'),
            ]),
        );
        assert.deepEqual(p2aAfterCompletedP0, [
            'completed',
            'current',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const legacyP3WithoutP2History = NORMAL_PHASE_PROGRESS_STEPS.map(
            (step) => normalProgressStepState(step, 'p3', []),
        );
        assert.deepEqual(legacyP3WithoutP2History, [
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const legacyP3WithCompletedP2 = NORMAL_PHASE_PROGRESS_STEPS.map(
            (step) =>
                normalProgressStepState(step, 'p3', [
                    timelineItem('p2a', 'completed'),
                ]),
        );
        assert.deepEqual(legacyP3WithCompletedP2, [
            'upcoming',
            'completed',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);
    });

    it('surfaces active legacy phases from the timeline when needed', () => {
        const activeLegacyTimelineItem = {
            id: 1,
            sequence: 1,
            phase_code: 'p3',
            phase_label: 'Ready to Join',
            status: 'active',
            status_label: 'Active',
            planned_start_at: null,
            planned_end_at: null,
            actual_start_at: '2026-09-01',
            actual_end_at: null,
            details: null,
            remarks: null,
            has_pending_correction: false,
            has_approved_correction: false,
        } satisfies PhaseTimelineItem;
        const completedLegacyTimelineItem = {
            ...activeLegacyTimelineItem,
            status: 'completed',
            status_label: 'Completed',
            actual_end_at: '2026-09-02',
        } satisfies PhaseTimelineItem;

        assert.equal(
            resolveActiveLegacyPhaseContext('p2a', [
                completedLegacyTimelineItem,
            ]),
            null,
        );
        assert.equal(
            resolveActiveLegacyPhaseContext('p4', [activeLegacyTimelineItem]),
            'Legacy phase · P3 Ready to Join',
        );
    });
});
