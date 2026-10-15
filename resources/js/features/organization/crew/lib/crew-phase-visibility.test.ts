import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PhaseTimelineItem } from '../types.ts';
import {
    isHomeRedeployPhaseCode,
    isOnVesselPhaseCode,
    LEGACY_CREW_PHASES,
    legacyPhaseContextLabel,
    NORMAL_PHASE_PROGRESS_STEPS,
    NORMAL_VISIBLE_CREW_PHASES,
    normalProgressSkippedStagesNote,
    normalProgressStepState,
    normalVisiblePhaseFilterOptions,
    resolveActiveLegacyPhaseContext,
    visiblePhaseTimelineItems,
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
    it('treats modern p2 as onboard and p4 as home without confusing legacy p4 onboard rows', () => {
        assert.equal(isOnVesselPhaseCode('p2'), true);
        assert.equal(isOnVesselPhaseCode('p4'), false);
        assert.equal(isOnVesselPhaseCode('p2a'), false);
        assert.equal(isHomeRedeployPhaseCode('p4'), true);
        assert.equal(isHomeRedeployPhaseCode('p3'), false);
        assert.equal(isHomeRedeployPhaseCode('p6'), true);
    });

    it('keeps modern p3 demobilisation standby visible in phase timeline items', () => {
        const items = visiblePhaseTimelineItems([
            timelineItem('p2', 'completed'),
            timelineItem('p3', 'active'),
            timelineItem('p1', 'completed'),
            timelineItem('p4', 'completed'),
        ]);

        assert.deepEqual(
            items.map((item) => item.phase_code),
            ['p2', 'p3', 'p4'],
        );
        assert.equal(
            items.some((item) => item.phase_code === 'p1'),
            false,
        );
    });

    it('marks modern p3 as the current demobilisation progress step', () => {
        const states = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p3', [
                timelineItem('p0', 'completed'),
                timelineItem('p1a', 'completed'),
                timelineItem('p2', 'completed'),
            ]),
        );

        assert.deepEqual(states, [
            'completed',
            'completed',
            'completed',
            'current',
            'upcoming',
        ]);
    });

    it('keeps a central normal visible phase list without legacy phases', () => {
        assert.deepEqual(NORMAL_VISIBLE_CREW_PHASES, [
            'p0',
            'p1a',
            'p1b',
            'p2',
            'p3',
            'p4',
        ]);
        assert.deepEqual(LEGACY_CREW_PHASES, ['p1', 'p2a', 'p2b', 'p5', 'p6']);
    });

    it('does not include legacy placeholders in the normal progress path', () => {
        const stepKeys = NORMAL_PHASE_PROGRESS_STEPS.map((step) => step.key);
        const stepCodes = NORMAL_PHASE_PROGRESS_STEPS.map((step) => step.code);

        assert.deepEqual(stepKeys, ['p0', 'prejoin', 'p2', 'p3', 'p4']);
        assert.deepEqual(stepCodes, ['P0', 'P1A/B', 'P2', 'P3', 'P4']);
        assert.equal(stepKeys.includes('p1'), false);
        assert.equal(stepCodes.includes('P1'), false);
        assert.equal(stepKeys.includes('p2a'), false);
    });

    it('excludes legacy phases from normal current phase filter options', () => {
        const options = normalVisiblePhaseFilterOptions();

        assert.equal(
            options.some((option) => option.value === 'p1'),
            false,
        );
        assert.equal(
            options.some((option) => option.value === 'p2a'),
            false,
        );
        assert.equal(
            options.some((option) => option.value === 'p1a'),
            true,
        );
    });

    it('formats legacy context labels for active legacy phases', () => {
        assert.match(legacyPhaseContextLabel('p1') ?? '', /Legacy phase · P1/i);
        assert.match(
            legacyPhaseContextLabel('p2a') ?? '',
            /Legacy phase · P2A/i,
        );
        assert.equal(legacyPhaseContextLabel('p1a'), null);
    });

    it('does not mark prior normal phases completed for legacy current phases without timeline evidence', () => {
        const p1TravelIn = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p1', []),
        );
        assert.deepEqual(p1TravelIn, [
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const legacyP2a = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p2a', []),
        );
        assert.deepEqual(legacyP2a, [
            'skipped',
            'current',
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

        const p1aAfterCompletedP0 = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p1a', [
                timelineItem('p0', 'completed'),
            ]),
        );
        assert.deepEqual(p1aAfterCompletedP0, [
            'completed',
            'current',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const legacyP2aWithoutHistory = NORMAL_PHASE_PROGRESS_STEPS.map(
            (step) => normalProgressStepState(step, 'p2a', []),
        );
        assert.deepEqual(legacyP2aWithoutHistory, [
            'skipped',
            'current',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);

        const legacyP2aWithCompletedP0 = NORMAL_PHASE_PROGRESS_STEPS.map(
            (step) =>
                normalProgressStepState(step, 'p2a', [
                    timelineItem('p0', 'completed'),
                ]),
        );
        assert.deepEqual(legacyP2aWithCompletedP0, [
            'completed',
            'current',
            'upcoming',
            'upcoming',
            'upcoming',
        ]);
    });

    it('marks earlier path stages as skipped when crew advanced without recorded phases', () => {
        const timeline = [
            timelineItem('p0', 'completed'),
            timelineItem('p2', 'completed'),
        ];
        const p4WithPartialHistory = NORMAL_PHASE_PROGRESS_STEPS.map((step) =>
            normalProgressStepState(step, 'p4', timeline),
        );

        assert.deepEqual(p4WithPartialHistory, [
            'completed',
            'skipped',
            'completed',
            'skipped',
            'current',
        ]);
        assert.equal(
            normalProgressSkippedStagesNote('p4', timeline),
            'Passed without recorded phase: P1A/B Pre-joining / training, P3 Demobilisation',
        );
        assert.equal(normalProgressSkippedStagesNote('p0', []), null);
    });

    it('surfaces active legacy phases from the timeline when needed', () => {
        const activeLegacyTimelineItem = {
            id: 1,
            sequence: 1,
            phase_code: 'p1',
            phase_label: 'Travel In',
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
            resolveActiveLegacyPhaseContext('p1a', [
                completedLegacyTimelineItem,
            ]),
            null,
        );
        assert.match(
            resolveActiveLegacyPhaseContext('p2', [activeLegacyTimelineItem]) ??
                '',
            /Legacy phase · P1/i,
        );
    });
});
