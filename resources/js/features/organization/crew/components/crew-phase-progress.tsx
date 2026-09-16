import { Check, CircleDot } from 'lucide-react';
import type { ReactElement } from 'react';
import {
    NORMAL_PHASE_PROGRESS_STEPS,
    normalProgressStepDetail,
    normalProgressStepState,
    resolveActiveLegacyPhaseContext,
} from '@/features/organization/crew/lib/crew-phase-visibility';
import { cn } from '@/lib/utils';
import type { PhaseTimelineItem } from '../types';

export function CrewPhaseProgress({
    currentPhaseCode,
    phaseTimeline = [],
}: {
    currentPhaseCode: string | null;
    phaseTimeline?: PhaseTimelineItem[];
}): ReactElement {
    const legacyContext = resolveActiveLegacyPhaseContext(
        currentPhaseCode,
        phaseTimeline,
    );

    return (
        <div className="space-y-3">
            {legacyContext ? (
                <div className="rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                    {legacyContext}
                </div>
            ) : null}

            <div className="overflow-x-auto pb-1">
                <div
                    className="grid min-w-[640px] grid-cols-5"
                    role="list"
                    aria-label="Crew movement phase progress"
                >
                    {NORMAL_PHASE_PROGRESS_STEPS.map((step, index) => {
                        const state = normalProgressStepState(
                            step,
                            currentPhaseCode,
                            phaseTimeline,
                        );

                        return (
                            <div
                                key={step.key}
                                className="relative flex min-w-0 flex-col items-center px-1 text-center"
                                role="listitem"
                            >
                                {index <
                                NORMAL_PHASE_PROGRESS_STEPS.length - 1 ? (
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
                                    {normalProgressStepDetail(
                                        step,
                                        currentPhaseCode,
                                        phaseTimeline,
                                    )}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
