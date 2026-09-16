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

    it('maps legacy current phases onto the normal progress path without p1/p3 steps', () => {
        const p1States = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p1', []),
        );
        assert.deepEqual(p1States, [
            'completed',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const p3States = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p3', []),
        );
        assert.deepEqual(p3States, [
            'completed',
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
