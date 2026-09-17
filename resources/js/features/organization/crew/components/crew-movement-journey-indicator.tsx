import { CircleDot } from 'lucide-react';
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

export function CrewMovementJourneyIndicator({
    currentPhaseCode,
    className,
}: {
    currentPhaseCode: string | null;
    className?: string;
}): ReactElement | null {
    const normalizedCurrent = currentPhaseCode?.toLowerCase() ?? null;

    if (!normalizedCurrent) {
        return (
            <p
                className={cn(
                    'rounded-lg border border-dashed border-border/70 bg-muted/10 px-3 py-2 text-xs text-muted-foreground',
                    className,
                )}
            >
                No active mobilisation phase to display.
            </p>
        );
    }

    const legacy = isLegacyCrewPhase(normalizedCurrent);
    const currentCopy = crewPhaseCopy(normalizedCurrent);

    return (
        <div className={cn('space-y-2', className)}>
            <div className="overflow-x-auto pb-1">
                <div
                    className="flex min-w-max items-center gap-1"
                    role="list"
                    aria-label="Crew movement journey"
                >
                    {JOURNEY_STEPS.map((step, index) => {
                        const isCurrent =
                            step.code === normalizedCurrent ||
                            (step.code === 'p2a' &&
                                normalizedCurrent === 'p2a') ||
                            (step.code === 'p2b' &&
                                normalizedCurrent === 'p2b');
                        const isLegacyCurrent =
                            legacy &&
                            ((normalizedCurrent === 'p1' &&
                                step.code === 'p0') ||
                                (normalizedCurrent === 'p3' &&
                                    step.code === 'p2b'));

                        const highlighted = isCurrent || isLegacyCurrent;

                        return (
                            <div
                                key={step.code}
                                className="flex items-center gap-1"
                                role="listitem"
                            >
                                <div
                                    className={cn(
                                        'flex min-w-[3.25rem] flex-col items-center rounded-md px-1 py-1.5 text-center transition-colors',
                                        highlighted
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                    aria-current={
                                        highlighted ? 'step' : undefined
                                    }
                                >
                                    <span
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-full text-[10px] font-bold',
                                            highlighted
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted/70',
                                        )}
                                    >
                                        {highlighted ? (
                                            <CircleDot
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        ) : (
                                            step.label.slice(-1)
                                        )}
                                    </span>
                                    <span className="mt-1 text-[10px] font-semibold tracking-wide">
                                        {step.label}
                                    </span>
                                </div>
                                {index < JOURNEY_STEPS.length - 1 ? (
                                    <span
                                        className="text-[10px] text-muted-foreground/70"
                                        aria-hidden
                                    >
                                        →
                                    </span>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            </div>

            {currentCopy ? (
                <p className="text-xs text-muted-foreground">
                    Current:{' '}
                    <span className="font-medium text-foreground">
                        {currentCopy.code.toUpperCase()} · {currentCopy.label}
                    </span>
                    {legacy ? ' (legacy compatibility phase)' : null}
                </p>
            ) : null}
        </div>
    );
}
