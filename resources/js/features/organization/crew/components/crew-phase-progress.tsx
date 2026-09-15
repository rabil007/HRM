import { Check, CircleDot } from 'lucide-react';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import type { PhaseTimelineItem } from '../types';

const PROGRESS_STEPS = [
    {
        key: 'p0',
        code: 'P0',
        label: 'Pre-mobilisation',
        codes: ['p0'] as string[],
    },
    { key: 'p1', code: 'P1', label: 'Travel in', codes: ['p1'] as string[] },
    {
        key: 'p2',
        code: 'P2',
        label: 'Standby / training',
        codes: ['p2a', 'p2b'] as string[],
    },
    {
        key: 'p3',
        code: 'P3',
        label: 'Ready to join',
        codes: ['p3'] as string[],
    },
    { key: 'p4', code: 'P4', label: 'On vessel', codes: ['p4'] as string[] },
    {
        key: 'p5',
        code: 'P5',
        label: 'Demobilisation',
        codes: ['p5'] as string[],
    },
    {
        key: 'p6',
        code: 'P6',
        label: 'Home / redeploy',
        codes: ['p6'] as string[],
    },
];

type StepState = 'completed' | 'current' | 'upcoming';

function stepState(
    stepCodes: string[],
    currentCode: string | null,
    timeline: PhaseTimelineItem[],
): StepState {
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

function phaseDetail(
    stepCodes: string[],
    currentCode: string | null,
    timeline: PhaseTimelineItem[],
): string {
    const normalizedCurrent = currentCode?.toLowerCase() ?? null;
    const matchingPhase = [...timeline]
        .reverse()
        .find((phase) => stepCodes.includes(phase.phase_code.toLowerCase()));

    if (normalizedCurrent && stepCodes.includes(normalizedCurrent)) {
        return normalizedCurrent === 'p2a'
            ? 'Join standby'
            : normalizedCurrent === 'p2b'
              ? 'Training'
              : 'Current phase';
    }

    return matchingPhase?.status === 'completed' ? 'Completed' : 'Upcoming';
}

export function CrewPhaseProgress({
    currentPhaseCode,
    phaseTimeline = [],
}: {
    currentPhaseCode: string | null;
    phaseTimeline?: PhaseTimelineItem[];
}): ReactElement {
    return (
        <div className="overflow-x-auto pb-1">
            <div
                className="grid min-w-[800px] grid-cols-7"
                role="list"
                aria-label="Crew movement phase progress"
            >
                {PROGRESS_STEPS.map((step, index) => {
                    const state = stepState(
                        step.codes,
                        currentPhaseCode,
                        phaseTimeline,
                    );

                    return (
                        <div
                            key={step.key}
                            className="relative flex min-w-0 flex-col items-center px-1 text-center"
                            role="listitem"
                        >
                            {index < PROGRESS_STEPS.length - 1 ? (
                                <span
                                    className={cn(
                                        'absolute top-4 left-[calc(50%+16px)] h-0.5 w-[calc(100%-32px)]',
                                        state === 'completed'
                                            ? 'bg-emerald-500/70'
                                            : 'bg-border dark:bg-white/10',
                                    )}
                                    aria-hidden
                                />
                            ) : null}

                            <span
                                className={cn(
                                    'relative z-10 flex size-8 items-center justify-center rounded-full border-4 border-background text-[10px] font-bold shadow-sm ring-1 dark:border-background',
                                    state === 'current' &&
                                        'bg-primary text-primary-foreground ring-2 ring-primary/40 ring-offset-2 ring-offset-background',
                                    state === 'completed' &&
                                        'bg-emerald-500 text-white ring-emerald-500/30',
                                    state === 'upcoming' &&
                                        'bg-muted text-muted-foreground ring-border dark:ring-white/10',
                                )}
                                aria-current={
                                    state === 'current' ? 'step' : undefined
                                }
                            >
                                {state === 'completed' ? (
                                    <Check className="size-3.5" />
                                ) : state === 'current' ? (
                                    <CircleDot className="size-3.5" />
                                ) : (
                                    step.code
                                )}
                            </span>
                            <span
                                className={cn(
                                    'mt-2 text-[10px] font-bold tracking-wide uppercase',
                                    state === 'current'
                                        ? 'text-primary'
                                        : state === 'completed'
                                          ? 'text-emerald-600 dark:text-emerald-400'
                                          : 'text-muted-foreground',
                                )}
                            >
                                {step.code}
                            </span>
                            <span className="mt-0.5 line-clamp-1 max-w-full text-[11px] font-medium text-foreground/80">
                                {step.label}
                            </span>
                            <span className="mt-1 text-[10px] text-muted-foreground/60">
                                {phaseDetail(
                                    step.codes,
                                    currentPhaseCode,
                                    phaseTimeline,
                                )}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
