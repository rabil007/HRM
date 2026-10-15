import type { ReactElement } from 'react';
import { crewPhaseCopy } from '@/features/organization/crew/lib/crew-phase-descriptions';
import {
    NORMAL_VISIBLE_CREW_PHASES,
    normalizePhaseCodeForDisplay,
} from '@/features/organization/crew/lib/crew-phase-visibility';
import { cn } from '@/lib/utils';

const JOURNEY_STEPS = NORMAL_VISIBLE_CREW_PHASES.map((code) => ({
    code,
    label: code.toUpperCase(),
}));

function isHighlightedStep(
    stepCode: string,
    normalizedCurrent: string | null,
): boolean {
    return normalizedCurrent !== null && stepCode === normalizedCurrent;
}

export function CrewMovementJourneyIndicator({
    currentPhaseCode,
    className,
}: {
    currentPhaseCode: string | null;
    className?: string;
}): ReactElement | null {
    const normalizedCurrent =
        normalizePhaseCodeForDisplay(currentPhaseCode) ??
        currentPhaseCode?.toLowerCase() ??
        null;

    if (!normalizedCurrent) {
        return null;
    }

    const currentCopy = crewPhaseCopy(normalizedCurrent);

    return (
        <div className={cn('space-y-1.5', className)}>
            {currentCopy ? (
                <p className="text-xs text-muted-foreground">
                    Journey:{' '}
                    <span className="font-medium text-foreground">
                        {currentCopy.code.toUpperCase()} · {currentCopy.label}
                    </span>
                </p>
            ) : null}

            <div
                className="flex items-center gap-0.5"
                role="list"
                aria-label="Crew movement journey"
            >
                {JOURNEY_STEPS.map((step, index) => {
                    const highlighted = isHighlightedStep(
                        step.code,
                        normalizedCurrent,
                    );

                    return (
                        <div
                            key={step.code}
                            className="flex items-center gap-0.5"
                            role="listitem"
                        >
                            <span
                                className={cn(
                                    'inline-flex min-w-[1.75rem] items-center justify-center rounded px-1 py-0.5 text-[10px] font-semibold tracking-wide',
                                    highlighted
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted/60 text-muted-foreground',
                                )}
                                aria-current={highlighted ? 'step' : undefined}
                            >
                                {step.label}
                            </span>
                            {index < JOURNEY_STEPS.length - 1 ? (
                                <span
                                    className="text-[9px] text-muted-foreground/60"
                                    aria-hidden
                                >
                                    ·
                                </span>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
