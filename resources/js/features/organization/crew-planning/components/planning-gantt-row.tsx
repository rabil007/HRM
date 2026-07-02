import { useDroppable } from '@dnd-kit/core';
import type { MouseEvent, ReactElement } from 'react';
import { cn } from '@/lib/utils';
import {
    barPositionStyle,
    dateFromPointerRatio,
    todayLinePositionPercent,
} from '../lib/planning-gantt-math';
import type { GanttBar, PlanningPagePermissions, RowDropData } from '../types';
import { PlanningGanttBar } from './planning-bar-tooltip';

export const ROW_HEIGHT = 48;
export const RANK_LABEL_WIDTH = 112;

type Props = {
    rowKey: string;
    rankName: string;
    vesselId: number;
    rankId: number;
    bars: GanttBar[];
    rangeFrom: Date;
    rangeTo: Date;
    today: Date;
    highlightedCrewName: string;
    isHighlighted: boolean;
    timelineMinWidth: number;
    can: PlanningPagePermissions;
    isDraggingBar?: boolean;
    onRowClick?: (
        rowKey: string,
        vesselId: number,
        rankId: number,
        estimatedDate: string,
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
    bars,
    rangeFrom,
    rangeTo,
    today,
    highlightedCrewName,
    isHighlighted,
    timelineMinWidth,
    can,
    isDraggingBar = false,
    onRowClick,
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

    const handleBackgroundClick = (e: MouseEvent<HTMLDivElement>): void => {
        if (!can.create || !onRowClick || isDraggingBar) {
            return;
        }

        const target = e.target as HTMLElement;

        if (
            target.closest('[data-radix-popper-content-wrapper]') ??
            target.closest('.absolute')
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
            className={cn(
                'group relative flex border-b border-border/50 bg-background',
                isHighlighted && 'bg-amber-50/50 dark:bg-amber-950/30',
                isOver && 'bg-primary/5 dark:bg-primary/10',
                can.create && 'hover:bg-muted/30 dark:hover:bg-muted/20',
            )}
            style={{
                height: ROW_HEIGHT,
                minWidth: timelineMinWidth + RANK_LABEL_WIDTH,
            }}
        >
            {/* Rank label */}
            <div
                className={cn(
                    'sticky left-0 z-20 flex shrink-0 items-center border-r border-border/50 bg-background px-3',
                    isHighlighted && 'bg-amber-50/50 dark:bg-amber-950/30',
                    isOver && 'bg-primary/5 dark:bg-primary/10',
                    can.create &&
                        'group-hover:bg-muted/30 dark:group-hover:bg-muted/20',
                )}
                style={{ width: RANK_LABEL_WIDTH }}
            >
                <span className="truncate text-[11px] font-medium tracking-wide text-muted-foreground/60">
                    {rankName}
                </span>
            </div>

            <div
                data-timeline-container
                className="relative min-w-0 flex-1"
                style={{ minWidth: timelineMinWidth }}
            >
                {/* Today line */}
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

                {/* Hover click layer */}
                {can.create ? (
                    <div
                        className="absolute inset-0 z-0 cursor-crosshair"
                        title={`Click to plan assignment on ${rankName}`}
                        onClick={handleBackgroundClick}
                    />
                ) : null}

                <div className="relative z-10 h-full overflow-hidden">
                    {bars.map((bar) => {
                        const style = barPositionStyle(
                            bar.start,
                            bar.end,
                            rangeFrom,
                            rangeTo,
                        );
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
