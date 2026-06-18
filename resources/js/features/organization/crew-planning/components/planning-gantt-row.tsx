import { useDroppable } from '@dnd-kit/core';
import type { MouseEvent, ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { barPositionStyle, dateFromPointerRatio } from '../lib/planning-gantt-math';
import { PlanningGanttBar } from './planning-bar-tooltip';
import type { GanttBar, PlanningPagePermissions, RowDropData } from '../types';

export const ROW_HEIGHT = 48;

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
    rowRef?: React.RefObject<HTMLDivElement | null>;
    can: PlanningPagePermissions;
    isDraggingBar?: boolean;
    onRowClick?: (rowKey: string, vesselId: number, rankId: number, estimatedDate: string) => void;
    onEditBar?: (bar: GanttBar) => void;
    onDeleteBar?: (bar: GanttBar) => void;
    onConfirmBar?: (bar: GanttBar) => void;
};

function todayLineStyle(today: Date, rangeFrom: Date, rangeTo: Date): React.CSSProperties | null {
    const totalMs = rangeTo.getTime() - rangeFrom.getTime();
    if (totalMs <= 0) {
        return null;
    }
    const pos = ((today.getTime() - rangeFrom.getTime()) / totalMs) * 100;
    if (pos < 0 || pos > 100) {
        return null;
    }
    return { left: `${pos}%` };
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
    rowRef,
    can,
    isDraggingBar = false,
    onRowClick,
    onEditBar,
    onDeleteBar,
    onConfirmBar,
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
        if (target.closest('[data-radix-popper-content-wrapper]') ?? target.closest('.absolute')) {
            return;
        }
        const rect = e.currentTarget.getBoundingClientRect();
        const ratio = (e.clientX - rect.left) / rect.width;
        const estimatedDate = dateFromPointerRatio(ratio, rangeFrom, rangeTo);
        onRowClick(rowKey, vesselId, rankId, estimatedDate);
    };

    // Merge droppable ref with the external rowRef
    const setRef = (el: HTMLDivElement | null): void => {
        setDropRef(el);
        if (rowRef) {
            (rowRef as React.MutableRefObject<HTMLDivElement | null>).current = el;
        }
    };

    return (
        <div
            ref={setRef}
            data-row-key={rowKey}
            className={cn(
                'relative flex border-b transition-colors',
                isHighlighted && 'bg-yellow-50/50 dark:bg-yellow-900/10',
                isOver && 'bg-blue-50/60 dark:bg-blue-950/20',
                can.create && !isOver && 'group',
            )}
            style={{ height: ROW_HEIGHT }}
        >
            {todayStyle ? (
                <div
                    className="pointer-events-none absolute top-0 bottom-0 z-10 w-px bg-red-500/70"
                    style={todayStyle}
                    aria-hidden
                />
            ) : null}

            {can.create ? (
                <div
                    className="absolute inset-0 z-0 cursor-copy opacity-0 transition-opacity group-hover:opacity-100"
                    title={`Click to plan assignment on ${rankName}`}
                    onClick={handleBackgroundClick}
                />
            ) : null}

            <div className="relative z-10 flex-1 overflow-hidden">
                {bars.map((bar) => {
                    const style = barPositionStyle(bar.start, bar.end, rangeFrom, rangeTo);
                    const isBarHighlighted =
                        lowerSearch !== '' &&
                        bar.employee_name.toLowerCase().includes(lowerSearch);

                    return (
                        <PlanningGanttBar
                            key={`${bar.source}-${bar.id}`}
                            bar={bar}
                            style={style}
                            highlighted={isBarHighlighted}
                            can={can}
                            rangeFrom={rangeFrom}
                            rangeTo={rangeTo}
                            onEdit={onEditBar}
                            onDelete={onDeleteBar}
                            onConfirm={onConfirmBar}
                        />
                    );
                })}
            </div>
        </div>
    );
}
