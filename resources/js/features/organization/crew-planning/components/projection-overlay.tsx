import type { KeyboardEvent, MouseEvent, ReactElement } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { barPositionStyle } from '../lib/planning-gantt-math';
import type { PlanningProjectionPeriod, PlanningProjectionRow } from '../types';

type Props = {
    projection: PlanningProjectionRow;
    rangeFrom: Date;
    rangeTo: Date;
    canCreate: boolean;
    onGapClick?: (period: PlanningProjectionPeriod) => void;
};

function exceptionPeriods(
    periods: PlanningProjectionPeriod[],
): PlanningProjectionPeriod[] {
    return periods.filter((period) => period.gap > 0 || period.excess > 0);
}

function periodTitle(
    period: PlanningProjectionPeriod,
    requiredCount: number,
): string {
    if (period.gap > 0) {
        return [
            'Projected gap',
            `Required: ${requiredCount}`,
            `Projected: ${period.projected_count}`,
            `Short: ${period.gap}`,
            `${formatDisplayDate(period.from)} → ${formatDisplayDate(period.to)}`,
        ].join('\n');
    }

    return [
        'Projected overlap',
        `Required: ${requiredCount}`,
        `Projected: ${period.projected_count}`,
        `Excess: ${period.excess}`,
        `${formatDisplayDate(period.from)} → ${formatDisplayDate(period.to)}`,
    ].join('\n');
}

export function ProjectionOverlay({
    projection,
    rangeFrom,
    rangeTo,
    canCreate,
    onGapClick,
}: Props): ReactElement | null {
    const periods = exceptionPeriods(projection.periods);

    if (periods.length === 0) {
        return null;
    }

    return (
        <div
            className="absolute inset-0 z-[2]"
            data-projection-overlay={projection.row_key}
        >
            {periods.map((period) => {
                const style = barPositionStyle(
                    period.from,
                    period.to,
                    rangeFrom,
                    rangeTo,
                );

                if ('display' in style) {
                    return null;
                }

                const isGap = period.gap > 0;
                const interactive = isGap && canCreate && onGapClick != null;

                return (
                    <Tooltip
                        key={`${period.from}-${period.to}-${period.gap}-${period.excess}`}
                    >
                        <TooltipTrigger asChild>
                            <div
                                data-projection-band
                                className={cn(
                                    'absolute top-1 bottom-1 rounded-sm',
                                    isGap
                                        ? 'bg-destructive/20 ring-1 ring-destructive/35 dark:bg-destructive/25 dark:ring-destructive/45'
                                        : 'pointer-events-none bg-amber-500/15 ring-1 ring-amber-500/30 dark:bg-amber-400/20 dark:ring-amber-400/40',
                                    interactive &&
                                        'cursor-crosshair transition-colors hover:bg-destructive/30 dark:hover:bg-destructive/35',
                                    !interactive &&
                                        isGap &&
                                        'pointer-events-none',
                                )}
                                style={style}
                                role={interactive ? 'button' : undefined}
                                tabIndex={interactive ? 0 : undefined}
                                aria-label={
                                    interactive
                                        ? `Plan crew for projected gap starting ${period.from}`
                                        : undefined
                                }
                                title={periodTitle(
                                    period,
                                    projection.required_count,
                                )}
                                onClick={
                                    interactive
                                        ? (
                                              event: MouseEvent<HTMLDivElement>,
                                          ) => {
                                              event.stopPropagation();
                                              onGapClick(period);
                                          }
                                        : undefined
                                }
                                onKeyDown={
                                    interactive
                                        ? (
                                              event: KeyboardEvent<HTMLDivElement>,
                                          ) => {
                                              if (
                                                  event.key === 'Enter' ||
                                                  event.key === ' '
                                              ) {
                                                  event.preventDefault();
                                                  event.stopPropagation();
                                                  onGapClick(period);
                                              }
                                          }
                                        : undefined
                                }
                            />
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            className="max-w-xs whitespace-pre-line"
                        >
                            {periodTitle(period, projection.required_count)}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

export function projectionExceptionLabel(
    status: PlanningProjectionRow['status'],
): string | null {
    switch (status) {
        case 'current_gap':
            return 'Gap';
        case 'future_gap':
            return 'Future gap';
        case 'overlap':
            return 'Overlap';
        default:
            return null;
    }
}
