import type { ReactElement } from 'react';
import { crewPhaseCopy } from '@/features/organization/crew/lib/crew-phase-descriptions';
import { isLegacyCrewPhase } from '@/features/organization/crew/lib/crew-phase-visibility';
import { cn } from '@/lib/utils';

const JOURNEY_STEPS = [
    { code: 'p0', label: 'P0' },
    { code: 'p2a', label: 'P2A' },
    { code: 'p2b', label: 'P2B' },
    { code: 'p4', label: 'P4' },
    { code: 'p5', label: 'P5' },
    { code: 'p6', label: 'P6' },
] as const;

function isHighlightedStep(
    stepCode: string,
    normalizedCurrent: string,
    legacy: boolean,
): boolean {
    if (stepCode === normalizedCurrent) {
        return true;
    }

    if (legacy) {
        if (normalizedCurrent === 'p1' && stepCode === 'p0') {
            return true;
        }

        if (normalizedCurrent === 'p3' && stepCode === 'p2b') {
            return true;
        }
    }

    return false;
}

export function CrewMovementJourneyIndicator({
    currentPhaseCode,
    className,
}: {
    currentPhaseCode: string | null;
    className?: string;
}): ReactElement | null {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;

    if (!normalizedCurrent) {
        return null;
    }

    const legacy = isLegacyCrewPhase(normalizedCurrent);
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
                        legacy,
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
