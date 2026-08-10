import { useDroppable } from '@dnd-kit/core';
import type { CSSProperties, MouseEvent, ReactElement } from 'react';
import { cn } from '@/lib/utils';
import {
    assignBarsToLanes,
    laneBarHeight,
    laneCountForBars,
    laneTopOffset,
    rowHeightForLaneCount,
} from '../lib/assign-bars-to-lanes';
import {
    barPositionStyle,
    dateFromPointerRatio,
    todayLinePositionPercent,
} from '../lib/planning-gantt-math';
import type {
    GanttBar,
    PlanningPagePermissions,
    PlanningProjectionPeriod,
    PlanningProjectionRow,
    RowDropData,
} from '../types';
import { PlanningGanttBar } from './planning-bar-tooltip';
import {
    ProjectionOverlay,
    projectionExceptionLabel,
} from './projection-overlay';

/** Historic single-lane default; multi-lane rows use rowHeightForLaneCount(). */
export const ROW_HEIGHT = 48;
export const RANK_LABEL_WIDTH = 112;

type Props = {
    rowKey: string;
    rankName: string;
    vesselId: number;
    rankId: number;
    requiredCount?: number;
    bars: GanttBar[];
    rangeFrom: Date;
    rangeTo: Date;
    today: Date;
    highlightedCrewName: string;
    isHighlighted: boolean;
    timelineMinWidth: number;
    can: PlanningPagePermissions;
    projection?: PlanningProjectionRow | null;
    showCoverage?: boolean;
    isDraggingBar?: boolean;
    onRowClick?: (
        rowKey: string,
        vesselId: number,
        rankId: number,
        estimatedDate: string,
    ) => void;
    onGapClick?: (
        vesselId: number,
        rankId: number,
        period: PlanningProjectionPeriod,
    ) => void;
    onEditBar?: (bar: GanttBar) => void;
    onDeleteBar?: (bar: GanttBar) => void;
};

function todayLineStyle(
    today: Date,
    rangeFrom: Date,
    rangeTo: Date,
): React.CSSProperties | null {
    const pos = todayLinePositionPercent(today, rangeFrom, rangeTo);

    if (pos === null) {
        return null;
    }

    return { left: `${pos}%`, transform: 'translateX(-50%)' };
}

export function PlanningGanttRow({
    rowKey,
    rankName,
    vesselId,
    rankId,
    requiredCount,
    bars,
    rangeFrom,
    rangeTo,
    today,
    highlightedCrewName,
    isHighlighted,
    timelineMinWidth,
    can,
    projection = null,
    showCoverage = false,
    isDraggingBar = false,
    onRowClick,
    onGapClick,
    onEditBar,
    onDeleteBar,
}: Props): ReactElement {
    const dropData: RowDropData = { type: 'row', vesselId, rankId };
    const { setNodeRef: setDropRef, isOver } = useDroppable({
        id: `row:${rowKey}`,
        data: dropData,
    });

    const todayStyle = todayLineStyle(today, rangeFrom, rangeTo);
    const lowerSearch = highlightedCrewName.toLowerCase();
    const exceptionLabel = projection
        ? projectionExceptionLabel(projection.status)
        : null;
    const manningRequired = projection?.required_count ?? requiredCount ?? null;
    const laneCount = laneCountForBars(bars);
    const rowHeight = rowHeightForLaneCount(laneCount);
    const barHeight = laneBarHeight(laneCount);
    const lanedBars = assignBarsToLanes(bars);
    const dropTarget = { vesselId, rankId };

    const handleBackgroundClick = (e: MouseEvent<HTMLDivElement>): void => {
        if (!can.create || !onRowClick || isDraggingBar) {
            return;
        }

        const target = e.target as HTMLElement;

        if (
            target.closest('[data-radix-popper-content-wrapper]') ??
            target.closest('[data-projection-band]') ??
            target.closest('[data-planning-bar]')
        ) {
            return;
        }

        const rect = e.currentTarget.getBoundingClientRect();
        const ratio = (e.clientX - rect.left) / rect.width;
        const estimatedDate = dateFromPointerRatio(ratio, rangeFrom, rangeTo);
        onRowClick(rowKey, vesselId, rankId, estimatedDate);
    };

    return (
        <div
            ref={setDropRef}
            data-row-key={rowKey}
            data-vessel-id={vesselId}
            data-rank-id={rankId}
            data-lane-count={laneCount}
            className={cn(
                'group relative flex border-b border-border/50 bg-background',
                isHighlighted && 'bg-amber-50/50 dark:bg-amber-950/30',
                isOver && 'bg-primary/5 dark:bg-primary/10',
                can.create && 'hover:bg-muted/30 dark:hover:bg-muted/20',
            )}
            style={{
                height: rowHeight,
                minWidth: timelineMinWidth + RANK_LABEL_WIDTH,
            }}
        >
            <div
                className={cn(
                    'sticky left-0 z-20 flex shrink-0 flex-col justify-center gap-0.5 border-r border-border/50 bg-background px-2.5',
                    isHighlighted && 'bg-amber-50/50 dark:bg-amber-950/30',
                    isOver && 'bg-primary/5 dark:bg-primary/10',
                    can.create &&
                        'group-hover:bg-muted/30 dark:group-hover:bg-muted/20',
                )}
                style={{ width: RANK_LABEL_WIDTH }}
            >
                <span className="truncate text-[11px] font-medium tracking-wide text-muted-foreground/70">
                    {rankName}
                </span>
                {manningRequired != null && showCoverage ? (
                    <div className="flex min-w-0 items-center gap-1">
                        <span className="truncate text-[9px] text-muted-foreground/55">
                            Required {manningRequired}
                        </span>
                        {exceptionLabel ? (
                            <span
                                className={cn(
                                    'shrink-0 rounded px-1 py-px text-[8px] font-semibold tracking-wide uppercase',
                                    projection?.status === 'overlap'
                                        ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                        : 'bg-destructive/15 text-destructive',
                                )}
                            >
                                {exceptionLabel}
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>

            <div
                data-timeline-container
                className="relative min-w-0 flex-1"
                style={{ minWidth: timelineMinWidth }}
            >
                {todayStyle ? (
                    <div
                        className="pointer-events-none absolute top-0 bottom-0 z-[1] w-[2px] bg-red-500/70 shadow-[0_0_4px_rgba(239,68,68,0.4)]"
                        style={todayStyle}
                        aria-hidden
                    >
                        <div className="absolute -top-0 left-1/2 h-2 w-2 -translate-x-1/2 rounded-full bg-red-500 shadow-sm" />
                        <div className="absolute bottom-0 left-1/2 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-red-500/60" />
                    </div>
                ) : null}

                {can.create ? (
                    <div
                        className="absolute inset-0 z-0 cursor-crosshair"
                        title={`Click to plan assignment on ${rankName}`}
                        onClick={handleBackgroundClick}
                        data-row-drop-target={`${dropTarget.vesselId}:${dropTarget.rankId}`}
                    />
                ) : null}

                {showCoverage && projection ? (
                    <ProjectionOverlay
                        projection={projection}
                        rangeFrom={rangeFrom}
                        rangeTo={rangeTo}
                        today={today}
                        canCreate={can.create}
                        onGapClick={
                            onGapClick
                                ? (period) =>
                                      onGapClick(vesselId, rankId, period)
                                : undefined
                        }
                    />
                ) : null}

                <div className="relative z-10 h-full overflow-hidden">
                    {lanedBars.map(({ bar, lane }) => {
                        const horizontal = barPositionStyle(
                            bar.start,
                            bar.end,
                            rangeFrom,
                            rangeTo,
                            bar.is_open_ended,
                        );

                        if ('display' in horizontal) {
                            return null;
                        }

                        const style: CSSProperties = {
                            ...horizontal,
                            top: laneTopOffset(lane, laneCount),
                            height: barHeight,
                        };
                        const isBarHighlighted =
                            lowerSearch !== '' &&
                            bar.employee_name
                                .toLowerCase()
                                .includes(lowerSearch);

                        return (
                            <PlanningGanttBar
                                key={bar.id}
                                bar={bar}
                                style={style}
                                highlighted={isBarHighlighted}
                                can={can}
                                rangeFrom={rangeFrom}
                                rangeTo={rangeTo}
                                onEdit={onEditBar}
                                onDelete={onDeleteBar}
                            />
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
