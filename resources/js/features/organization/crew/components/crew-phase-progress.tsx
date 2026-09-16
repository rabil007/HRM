import { Check, CircleDot, Minus } from 'lucide-react';
import type { ReactElement } from 'react';
import {
    NORMAL_PHASE_PROGRESS_STEPS,
    normalProgressConnectorComplete,
    normalProgressStepDetail,
    normalProgressStepStates,
    normalProgressSummary,
    resolveActiveLegacyPhaseContext,
} from '@/features/organization/crew/lib/crew-phase-visibility';
import type { NormalProgressStepState } from '@/features/organization/crew/lib/crew-phase-visibility';
import { cn } from '@/lib/utils';
import type { PhaseTimelineItem } from '../types';

function stepCircleClass(state: NormalProgressStepState): string {
    switch (state) {
        case 'current':
            return 'bg-primary text-primary-foreground ring-2 ring-primary/35 ring-offset-2 ring-offset-background';
        case 'completed':
            return 'bg-emerald-500 text-white ring-emerald-500/30';
        case 'skipped':
            return 'border border-dashed border-muted-foreground/35 bg-muted/40 text-muted-foreground ring-border dark:ring-white/10';
        default:
            return 'bg-muted/70 text-muted-foreground ring-border dark:bg-white/5 dark:ring-white/10';
    }
}

function stepLabelClass(state: NormalProgressStepState): string {
    switch (state) {
        case 'current':
            return 'text-primary';
        case 'completed':
            return 'text-emerald-600 dark:text-emerald-400';
        case 'skipped':
            return 'text-muted-foreground/80';
        default:
            return 'text-muted-foreground';
    }
}

function StepIcon({
    state,
    code,
}: {
    state: NormalProgressStepState;
    code: string;
}) {
    if (state === 'completed') {
        return <Check className="size-3.5" aria-hidden />;
    }

    if (state === 'current') {
        return <CircleDot className="size-3.5" aria-hidden />;
    }

    if (state === 'skipped') {
        return <Minus className="size-3.5" aria-hidden />;
    }

    return <span>{code}</span>;
}

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
    const summary = normalProgressSummary(currentPhaseCode, phaseTimeline);
    const states = normalProgressStepStates(currentPhaseCode, phaseTimeline);

    return (
        <div className="space-y-4">
            {legacyContext ? (
                <div className="rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                    {legacyContext}
                </div>
            ) : null}

            {summary ? (
                <p className="text-sm font-medium text-foreground">{summary}</p>
            ) : null}

            <div className="overflow-x-auto pb-1">
                <div
                    className="grid min-w-[720px] grid-cols-5 gap-1"
                    role="list"
                    aria-label="Crew movement phase progress"
                >
                    {NORMAL_PHASE_PROGRESS_STEPS.map((step, index) => {
                        const state = states[index];
                        const connectorComplete =
                            normalProgressConnectorComplete(
                                index,
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
                                            'absolute top-4 left-[calc(50%+18px)] h-0.5 w-[calc(100%-36px)] rounded-full transition-colors',
                                            connectorComplete
                                                ? 'bg-emerald-500/75'
                                                : 'bg-border dark:bg-white/10',
                                        )}
                                        aria-hidden
                                    />
                                ) : null}

                                <span
                                    className={cn(
                                        'relative z-10 flex size-8 items-center justify-center rounded-full border-4 border-background text-[10px] font-bold shadow-sm dark:border-background',
                                        stepCircleClass(state),
                                    )}
                                    aria-current={
                                        state === 'current' ? 'step' : undefined
                                    }
                                >
                                    <StepIcon state={state} code={step.code} />
                                </span>

                                <span
                                    className={cn(
                                        'mt-2 text-[10px] font-bold tracking-wide uppercase',
                                        stepLabelClass(state),
                                    )}
                                >
                                    {step.code}
                                </span>
                                <span className="mt-0.5 line-clamp-2 max-w-full text-[11px] leading-snug font-medium text-foreground/85">
                                    {step.label}
                                </span>
                                <span
                                    className={cn(
                                        'mt-1 line-clamp-2 max-w-full text-[10px] leading-snug',
                                        state === 'current'
                                            ? 'font-medium text-primary/80'
                                            : 'text-muted-foreground/70',
                                    )}
                                >
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

            <p className="text-[11px] leading-relaxed text-muted-foreground">
                Steps follow the normal operating path. Earlier stages without a
                recorded phase are shown as passed; legacy P1/P3 phases appear
                separately when present.
            </p>
        </div>
    );
}
