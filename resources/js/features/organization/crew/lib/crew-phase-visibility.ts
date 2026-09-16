import type { PhaseTimelineItem } from '../types.ts';
import { crewPhaseCopy } from './crew-phase-descriptions.ts';

export const NORMAL_VISIBLE_CREW_PHASES = [
    'p0',
    'p2a',
    'p2b',
    'p4',
    'p5',
    'p6',
] as const;

export const LEGACY_CREW_PHASES = ['p1', 'p3'] as const;

export type NormalVisibleCrewPhase =
    (typeof NORMAL_VISIBLE_CREW_PHASES)[number];
export type LegacyCrewPhase = (typeof LEGACY_CREW_PHASES)[number];

export type NormalPhaseProgressStep = {
    key: string;
    code: string;
    label: string;
    codes: string[];
};

export const NORMAL_PHASE_PROGRESS_STEPS: NormalPhaseProgressStep[] = [
    {
        key: 'p0',
        code: 'P0',
        label: 'Pre-mobilisation',
        codes: ['p0'],
    },
    {
        key: 'p2',
        code: 'P2',
        label: 'Standby / training',
        codes: ['p2a', 'p2b'],
    },
    {
        key: 'p4',
        code: 'P4',
        label: 'On vessel',
        codes: ['p4'],
    },
    {
        key: 'p5',
        code: 'P5',
        label: 'Demobilisation',
        codes: ['p5'],
    },
    {
        key: 'p6',
        code: 'P6',
        label: 'Home / redeploy',
        codes: ['p6'],
    },
];

export const NORMAL_PHASE_PATH_STEPS = NORMAL_PHASE_PROGRESS_STEPS.map(
    (step) => ({
        code: step.key,
        label: step.code,
        description: step.label,
        matchCodes: step.codes,
    }),
);

export type NormalProgressStepState = 'completed' | 'current' | 'upcoming';

export function isLegacyCrewPhase(
    code: string | null | undefined,
): code is LegacyCrewPhase {
    return LEGACY_CREW_PHASES.includes(
        (code ?? '').toLowerCase() as LegacyCrewPhase,
    );
}

export function isNormalVisibleCrewPhase(
    code: string | null | undefined,
): code is NormalVisibleCrewPhase {
    return NORMAL_VISIBLE_CREW_PHASES.includes(
        (code ?? '').toLowerCase() as NormalVisibleCrewPhase,
    );
}

export function legacyPhaseContextLabel(
    code: string | null | undefined,
): string | null {
    const copy = crewPhaseCopy(code);

    if (!copy || !isLegacyCrewPhase(copy.code)) {
        return null;
    }

    return `Legacy phase · ${copy.code.toUpperCase()} ${copy.label}`;
}

export function normalVisiblePhaseFilterOptions(): Array<{
    value: string;
    label: string;
}> {
    return NORMAL_VISIBLE_CREW_PHASES.map((code) => {
        const copy = crewPhaseCopy(code);

        return {
            value: code,
            label: `${code.toUpperCase()} · ${copy?.label ?? code}`,
        };
    });
}

function completedPhaseCodes(timeline: PhaseTimelineItem[]): Set<string> {
    return new Set(
        timeline
            .filter((item) => item.status === 'completed')
            .map((item) => item.phase_code.toLowerCase()),
    );
}

export function resolveActiveLegacyPhaseContext(
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): string | null {
    const currentLabel = legacyPhaseContextLabel(currentPhaseCode);

    if (currentLabel) {
        return currentLabel;
    }

    const activeLegacy = phaseTimeline.find(
        (item) =>
            item.status === 'active' && isLegacyCrewPhase(item.phase_code),
    );

    return activeLegacy
        ? legacyPhaseContextLabel(activeLegacy.phase_code)
        : null;
}

export function normalProgressStepState(
    step: NormalPhaseProgressStep,
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): NormalProgressStepState {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;
    const completedCodes = completedPhaseCodes(phaseTimeline);

    if (normalizedCurrent === 'p1') {
        if (step.key === 'p0') {
            return 'completed';
        }

        return 'upcoming';
    }

    if (normalizedCurrent === 'p3') {
        if (step.key === 'p0' || step.key === 'p2') {
            return 'completed';
        }

        return 'upcoming';
    }

    if (normalizedCurrent && step.codes.includes(normalizedCurrent)) {
        return 'current';
    }

    if (step.key === 'p0') {
        if (
            step.codes.some((code) => completedCodes.has(code)) ||
            completedCodes.has('p1')
        ) {
            return 'completed';
        }
    }

    if (step.key === 'p2') {
        if (
            step.codes.some((code) => completedCodes.has(code)) ||
            completedCodes.has('p3')
        ) {
            return 'completed';
        }
    }

    if (step.codes.some((code) => completedCodes.has(code))) {
        return 'completed';
    }

    return 'upcoming';
}

export function normalProgressStepDetail(
    step: NormalPhaseProgressStep,
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): string {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;
    const state = normalProgressStepState(
        step,
        currentPhaseCode,
        phaseTimeline,
    );
    const matchingPhase = [...phaseTimeline]
        .reverse()
        .find((phase) => step.codes.includes(phase.phase_code.toLowerCase()));

    if (state === 'current') {
        if (normalizedCurrent === 'p2a') {
            return 'Join standby';
        }

        if (normalizedCurrent === 'p2b') {
            return 'Training';
        }

        return 'Current phase';
    }

    return matchingPhase?.status === 'completed' || state === 'completed'
        ? 'Completed'
        : 'Upcoming';
}

export function resolveNormalPhasePathIndex(
    currentPhaseCode: string | null,
): number {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;

    if (!normalizedCurrent) {
        return -1;
    }

    if (normalizedCurrent === 'p1') {
        return 0;
    }

    if (normalizedCurrent === 'p3') {
        return 1;
    }

    return NORMAL_PHASE_PATH_STEPS.findIndex((step) =>
        step.matchCodes.includes(normalizedCurrent),
    );
}
