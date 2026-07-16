import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import type { PhaseTimelineItem } from '../types';

const PROGRESS_STEPS = [
    { key: 'p0', label: 'P0', codes: ['p0'] as string[] },
    { key: 'p1', label: 'P1', codes: ['p1'] as string[] },
    { key: 'p2', label: 'P2', codes: ['p2a', 'p2b'] as string[] },
    { key: 'p3', label: 'P3', codes: ['p3'] as string[] },
    { key: 'p4', label: 'P4', codes: ['p4'] as string[] },
    { key: 'p5', label: 'P5', codes: ['p5'] as string[] },
    { key: 'p6', label: 'P6', codes: ['p6'] as string[] },
];

function stepState(
    stepCodes: string[],
    currentCode: string | null,
    timeline: PhaseTimelineItem[],
): 'completed' | 'current' | 'upcoming' {
    const normalizedCurrent = currentCode?.toLowerCase() ?? null;
    const completedCodes = new Set(
        timeline
            .filter((item) => item.status === 'completed')
            .map((item) => item.phase_code.toLowerCase()),
    );

    if (normalizedCurrent && stepCodes.includes(normalizedCurrent)) {
        return 'current';
    }

    if (stepCodes.some((code) => completedCodes.has(code))) {
        return 'completed';
    }

    return 'upcoming';
}

function branchLabel(stepCodes: string[], currentCode: string | null): string {
    const normalizedCurrent = currentCode?.toLowerCase() ?? null;

    if (normalizedCurrent && stepCodes.includes(normalizedCurrent)) {
        return normalizedCurrent.toUpperCase();
    }

    return 'P2A/P2B';
}

export function CrewPhaseProgress({
    currentPhaseCode,
    phaseTimeline,
}: {
    currentPhaseCode: string | null;
    phaseTimeline: PhaseTimelineItem[];
}): ReactElement {
    return (
        <div
            className="flex flex-wrap items-center gap-1"
            role="list"
            aria-label="Crew movement phase progress"
        >
            {PROGRESS_STEPS.map((step, index) => {
                const state = stepState(
                    step.codes,
                    currentPhaseCode,
                    phaseTimeline,
                );
                const isBranchStep = step.codes.length > 1;
                const displayLabel = isBranchStep
                    ? branchLabel(step.codes, currentPhaseCode)
                    : step.label;

                return (
                    <div
                        key={step.key}
                        className="flex items-center gap-1"
                        role="listitem"
                    >
                        <div
                            className={cn(
                                'rounded-md border px-2 py-1 text-xs font-medium transition-colors',
                                state === 'current' &&
                                    'border-primary bg-primary/10 text-primary ring-1 ring-primary/20',
                                state === 'completed' &&
                                    'border-success/30 bg-success/10 text-success',
                                state === 'upcoming' &&
                                    'border-border bg-muted/30 text-muted-foreground',
                            )}
                            aria-current={
                                state === 'current' ? 'step' : undefined
                            }
                        >
                            {displayLabel}
                        </div>
                        {index < PROGRESS_STEPS.length - 1 ? (
                            <span
                                className="text-muted-foreground"
                                aria-hidden="true"
                            >
                                →
                            </span>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
