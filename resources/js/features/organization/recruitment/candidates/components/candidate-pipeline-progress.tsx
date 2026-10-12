import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { buildPipelineSteps } from '../lib/candidate-stage-workspace';
import type { CandidateStage } from '../types';
import { CANDIDATE_STAGE_LABELS } from '../types';

export function CandidatePipelineProgress({
    stage,
    className,
}: {
    stage: CandidateStage;
    className?: string;
}) {
    const steps = buildPipelineSteps(stage, CANDIDATE_STAGE_LABELS);
    const isRejected = stage === 'rejected';

    return (
        <div className={cn('space-y-2', className)}>
            <ol
                className="flex flex-wrap items-center gap-1.5 sm:gap-2"
                aria-label="Recruitment pipeline progress"
            >
                {steps.map((step, index) => {
                    const isCurrent = step.status === 'current' && !isRejected;
                    const isComplete =
                        step.status === 'complete' && !isRejected;

                    return (
                        <li
                            key={step.stage}
                            className="flex min-w-0 items-center gap-1.5 sm:gap-2"
                        >
                            {index > 0 ? (
                                <span
                                    className={cn(
                                        'hidden h-px w-4 sm:block sm:w-6',
                                        isComplete || isCurrent
                                            ? 'bg-foreground/30'
                                            : 'bg-border',
                                    )}
                                    aria-hidden
                                />
                            ) : null}
                            <span
                                className={cn(
                                    'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium sm:text-xs',
                                    isComplete &&
                                        'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300',
                                    isCurrent &&
                                        'border-foreground/25 bg-foreground/5 text-foreground',
                                    !isComplete &&
                                        !isCurrent &&
                                        'border-border/70 bg-muted/30 text-muted-foreground',
                                    isRejected &&
                                        'border-border/60 bg-muted/20 text-muted-foreground',
                                )}
                            >
                                {isComplete ? (
                                    <Check
                                        className="h-3 w-3 shrink-0"
                                        aria-hidden
                                    />
                                ) : (
                                    <span
                                        className={cn(
                                            'h-1.5 w-1.5 shrink-0 rounded-full',
                                            isCurrent
                                                ? 'bg-foreground'
                                                : 'bg-muted-foreground/40',
                                        )}
                                        aria-hidden
                                    />
                                )}
                                <span className="truncate">{step.label}</span>
                                {isCurrent ? (
                                    <span className="sr-only">(current)</span>
                                ) : null}
                            </span>
                        </li>
                    );
                })}
            </ol>

            {isRejected ? (
                <p className="text-xs font-medium text-rose-700 dark:text-rose-300">
                    Candidate is Rejected — reopen restores the recorded
                    pre-rejection stage.
                </p>
            ) : null}
        </div>
    );
}
