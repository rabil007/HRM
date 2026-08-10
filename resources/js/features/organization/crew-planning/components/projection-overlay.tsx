import { AlertTriangle } from 'lucide-react';
import type { KeyboardEvent, MouseEvent, ReactElement } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { inclusivePeriodPositionStyle } from '../lib/planning-gantt-math';
import {
    bandAriaLabel,
    isFutureGapPeriod,
    periodTitle,
    projectionBandMode,
} from '../lib/projection-band';
import type { PlanningProjectionPeriod, PlanningProjectionRow } from '../types';

type Props = {
    projection: PlanningProjectionRow;
    rangeFrom: Date;
    rangeTo: Date;
    today?: Date;
    canCreate: boolean;
    onGapClick?: (period: PlanningProjectionPeriod) => void;
};

function exceptionPeriods(
    periods: PlanningProjectionPeriod[],
): PlanningProjectionPeriod[] {
    return periods.filter((period) => period.gap > 0 || period.excess > 0);
}

export function ProjectionOverlay({
    projection,
    rangeFrom,
    rangeTo,
    today = new Date(),
    canCreate,
    onGapClick,
}: Props): ReactElement | null {
    const periods = exceptionPeriods(projection.periods);

    if (periods.length === 0) {
        return null;
    }

    return (
        <div
            className="pointer-events-none absolute inset-0 z-[2]"
            data-projection-overlay={projection.row_key}
        >
            {periods.map((period) => {
                const style = inclusivePeriodPositionStyle(
                    period.from,
                    period.to,
                    rangeFrom,
                    rangeTo,
                );

                if ('display' in style) {
                    return null;
                }

                const isGap = period.gap > 0;
                const isFuture = isGap && isFutureGapPeriod(period, today);
                const mode = projectionBandMode(
                    period,
                    canCreate,
                    onGapClick != null,
                );
                const actionable = mode === 'create';
                const label = bandAriaLabel(
                    period,
                    projection.required_count,
                    mode,
                    isFuture,
                );

                if (isFuture) {
                    return (
                        <Tooltip
                            key={`${period.from}-${period.to}-${period.gap}-${period.excess}`}
                        >
                            <TooltipTrigger asChild>
                                <div
                                    data-projection-band
                                    data-projection-band-mode={mode}
                                    data-projection-future-marker
                                    className={cn(
                                        'pointer-events-auto absolute top-1.5 z-[3] flex h-5 max-w-[130px] items-center gap-1 rounded-md border border-destructive/40 bg-destructive/15 px-1.5 py-0.5 text-[10px] font-semibold text-destructive shadow-xs transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 dark:border-destructive/50 dark:bg-destructive/25 dark:text-destructive-foreground',
                                        actionable
                                            ? 'cursor-pointer hover:bg-destructive/25 dark:hover:bg-destructive/35'
                                            : 'cursor-help',
                                    )}
                                    style={{ left: style.left }}
                                    role={actionable ? 'button' : undefined}
                                    tabIndex={0}
                                    aria-label={label}
                                    onClick={(
                                        event: MouseEvent<HTMLDivElement>,
                                    ) => {
                                        event.stopPropagation();

                                        if (actionable && onGapClick) {
                                            onGapClick(period);
                                        }
                                    }}
                                    onKeyDown={(
                                        event: KeyboardEvent<HTMLDivElement>,
                                    ) => {
                                        if (
                                            event.key !== 'Enter' &&
                                            event.key !== ' '
                                        ) {
                                            return;
                                        }

                                        event.preventDefault();
                                        event.stopPropagation();

                                        if (actionable && onGapClick) {
                                            onGapClick(period);
                                        }
                                    }}
                                >
                                    <AlertTriangle className="h-3 w-3 shrink-0" />
                                    <span className="truncate">
                                        Future Shortfall
                                    </span>
                                </div>
                            </TooltipTrigger>
                            <TooltipContent
                                side="top"
                                className="max-w-xs whitespace-pre-line"
                            >
                                {periodTitle(
                                    period,
                                    projection.required_count,
                                    true,
                                )}
                            </TooltipContent>
                        </Tooltip>
                    );
                }

                return (
                    <Tooltip
                        key={`${period.from}-${period.to}-${period.gap}-${period.excess}`}
                    >
                        <TooltipTrigger asChild>
                            <div
                                data-projection-band
                                data-projection-band-mode={mode}
                                className={cn(
                                    'pointer-events-auto absolute top-1 bottom-1 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                                    isGap
                                        ? 'bg-destructive/20 ring-1 ring-destructive/35 dark:bg-destructive/25 dark:ring-destructive/45'
                                        : 'bg-amber-500/15 ring-1 ring-amber-500/30 dark:bg-amber-400/20 dark:ring-amber-400/40',
                                    actionable
                                        ? 'cursor-crosshair transition-colors hover:bg-destructive/30 dark:hover:bg-destructive/35'
                                        : 'cursor-help',
                                )}
                                style={style}
                                role={actionable ? 'button' : undefined}
                                tabIndex={0}
                                aria-label={label}
                                onClick={(
                                    event: MouseEvent<HTMLDivElement>,
                                ) => {
                                    event.stopPropagation();

                                    if (actionable && onGapClick) {
                                        onGapClick(period);
                                    }
                                }}
                                onKeyDown={(
                                    event: KeyboardEvent<HTMLDivElement>,
                                ) => {
                                    if (
                                        event.key !== 'Enter' &&
                                        event.key !== ' '
                                    ) {
                                        return;
                                    }

                                    event.preventDefault();
                                    event.stopPropagation();

                                    if (actionable && onGapClick) {
                                        onGapClick(period);
                                    }
                                }}
                            />
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            className="max-w-xs whitespace-pre-line"
                        >
                            {periodTitle(
                                period,
                                projection.required_count,
                                false,
                            )}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

export { projectionExceptionLabel } from '../lib/projection-band';
