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

export type NormalProgressStepState =
    | 'completed'
    | 'current'
    | 'skipped'
    | 'upcoming';

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

function latestTimelinePhaseForStep(
    step: NormalPhaseProgressStep,
    phaseTimeline: PhaseTimelineItem[],
): PhaseTimelineItem | undefined {
    return [...phaseTimeline]
        .reverse()
        .find((phase) => step.codes.includes(phase.phase_code.toLowerCase()));
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

export function normalProgressStepStates(
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): NormalProgressStepState[] {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;
    const completedCodes = completedPhaseCodes(phaseTimeline);
    const currentPathIndex = resolveNormalPhasePathIndex(currentPhaseCode);

    if (normalizedCurrent && isLegacyCrewPhase(normalizedCurrent)) {
        return NORMAL_PHASE_PROGRESS_STEPS.map((step) => {
            if (step.codes.some((code) => completedCodes.has(code))) {
                return 'completed';
            }

            return 'upcoming';
        });
    }

    return NORMAL_PHASE_PROGRESS_STEPS.map((step, index) => {
        const isCurrent =
            normalizedCurrent !== null &&
            step.codes.includes(normalizedCurrent);
        const hasCompleted = step.codes.some((code) =>
            completedCodes.has(code),
        );

        if (isCurrent) {
            return 'current';
        }

        if (currentPathIndex >= 0) {
            if (index < currentPathIndex) {
                return hasCompleted ? 'completed' : 'skipped';
            }

            if (index > currentPathIndex) {
                return 'upcoming';
            }
        }

        return hasCompleted ? 'completed' : 'upcoming';
    });
}

export function normalProgressConnectorComplete(
    stepIndex: number,
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): boolean {
    const currentPathIndex = resolveNormalPhasePathIndex(currentPhaseCode);

    if (currentPathIndex >= 0) {
        return stepIndex < currentPathIndex;
    }

    const states = normalProgressStepStates(currentPhaseCode, phaseTimeline);

    return states[stepIndex] === 'completed';
}

export function normalProgressStepState(
    step: NormalPhaseProgressStep,
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): NormalProgressStepState {
    const stepIndex = NORMAL_PHASE_PROGRESS_STEPS.findIndex(
        (candidate) => candidate.key === step.key,
    );

    if (stepIndex < 0) {
        return 'upcoming';
    }

    return normalProgressStepStates(currentPhaseCode, phaseTimeline)[stepIndex];
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
    const matchingPhase = latestTimelinePhaseForStep(step, phaseTimeline);

    if (state === 'current') {
        if (normalizedCurrent === 'p2a') {
            return 'Join standby · now';
        }

        if (normalizedCurrent === 'p2b') {
            return 'Training · now';
        }

        const copy = crewPhaseCopy(normalizedCurrent);

        return copy ? `${copy.label} · now` : 'Current phase';
    }

    if (state === 'skipped') {
        return 'Passed without recorded phase';
    }

    if (state === 'completed') {
        const completedCode =
            matchingPhase?.phase_code.toUpperCase() ?? step.code;

        return `${completedCode} recorded`;
    }

    return 'Not reached yet';
}

export function normalProgressSummary(
    currentPhaseCode: string | null,
    phaseTimeline: PhaseTimelineItem[] = [],
): string | null {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;

    if (!normalizedCurrent) {
        return null;
    }

    const legacyContext = legacyPhaseContextLabel(normalizedCurrent);

    if (legacyContext) {
        return legacyContext;
    }

    const copy = crewPhaseCopy(normalizedCurrent);

    if (!copy) {
        return null;
    }

    const activePhase = phaseTimeline.find(
        (phase) =>
            phase.status === 'active' &&
            phase.phase_code.toLowerCase() === normalizedCurrent,
    );
    const startedAt = activePhase?.actual_start_at;

    if (startedAt) {
        return `Currently in ${copy.code.toUpperCase()} ${copy.label} since ${startedAt.slice(0, 10)}`;
    }

    return `Currently in ${copy.code.toUpperCase()} ${copy.label}`;
}

export function resolveNormalPhasePathIndex(
    currentPhaseCode: string | null,
): number {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;

    if (!normalizedCurrent || isLegacyCrewPhase(normalizedCurrent)) {
        return -1;
    }

    return NORMAL_PHASE_PATH_STEPS.findIndex((step) =>
        step.matchCodes.includes(normalizedCurrent),
    );
}
