import { router } from '@inertiajs/react';
import type { PointerEvent, ReactElement } from 'react';
import { useRef, useState } from 'react';
import { update as updateAssignment } from '@/actions/App/Http/Controllers/Organization/CrewPlanningAssignmentController';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    barPositionStyle,
    daysBetween,
    pxToDays,
    shiftDateRange,
} from '../lib/planning-gantt-math';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { AssignmentBarPopover } from './assignment-bar-popover';

type DragMode = 'move' | 'resize-left' | 'resize-right';

type DragState = {
    mode: DragMode;
    startX: number;
    originalStart: string;
    originalEnd: string;
    containerWidth: number;
};

type Props = {
    bar: GanttBar;
    style: React.CSSProperties;
    highlighted: boolean;
    can: PlanningPagePermissions;
    rangeFrom: Date;
    rangeTo: Date;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

export function DraggableAssignmentBar({
    bar,
    style,
    highlighted,
    can,
    rangeFrom,
    rangeTo,
    onEdit,
    onDelete,
}: Props): ReactElement {
    const dragRef = useRef<DragState | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const optimisticStartRef = useRef<string | null>(null);
    const optimisticEndRef = useRef<string | null>(null);
    const [liveStyle, setLiveStyle] = useState<React.CSSProperties | null>(null);
    const [isDragging, setIsDragging] = useState(false);

    const handlePointerDown = (e: PointerEvent<HTMLDivElement>, mode: DragMode): void => {
        if (!can.update) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        const rowEl = containerRef.current?.closest('[data-row-key]') as HTMLDivElement | null;
        const containerWidth = rowEl?.clientWidth ?? window.innerWidth;

        dragRef.current = {
            mode,
            startX: e.clientX,
            originalStart: bar.planned_join_date,
            originalEnd: bar.planned_leave_date,
            containerWidth,
        };
        optimisticStartRef.current = bar.planned_join_date;
        optimisticEndRef.current = bar.planned_leave_date;

        setIsDragging(true);
        setLiveStyle(style);

        const onMove = (me: globalThis.PointerEvent): void => {
            const drag = dragRef.current;

            if (!drag) {
                return;
            }

            const rawDays = pxToDays(me.clientX - drag.startX, drag.containerWidth, rangeFrom, rangeTo);
            const dayDelta = Math.round(rawDays);
            let newStart = drag.originalStart;
            let newEnd = drag.originalEnd;

            if (drag.mode === 'move') {
                const shifted = shiftDateRange(drag.originalStart, drag.originalEnd, dayDelta);
                newStart = shifted.start;
                newEnd = shifted.end;
            } else if (drag.mode === 'resize-left') {
                const maxDelta = daysBetween(drag.originalStart, drag.originalEnd) - 1;
                const clamped = Math.min(dayDelta, maxDelta);
                newStart = shiftDateRange(drag.originalStart, drag.originalStart, clamped).start;
                newEnd = drag.originalEnd;
            } else {
                const minDelta = -(daysBetween(drag.originalStart, drag.originalEnd) - 1);
                const clamped = Math.max(dayDelta, minDelta);
                newEnd = shiftDateRange(drag.originalEnd, drag.originalEnd, clamped).end;
                newStart = drag.originalStart;
            }

            optimisticStartRef.current = newStart;
            optimisticEndRef.current = newEnd;
            setLiveStyle(barPositionStyle(newStart, newEnd, rangeFrom, rangeTo));
        };

        const onUp = (): void => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);

            const drag = dragRef.current;
            dragRef.current = null;

            if (!drag) {
                setIsDragging(false);
                setLiveStyle(null);

                return;
            }

            const finalStart = optimisticStartRef.current ?? drag.originalStart;
            const finalEnd = optimisticEndRef.current ?? drag.originalEnd;

            if (finalStart === drag.originalStart && finalEnd === drag.originalEnd) {
                setIsDragging(false);
                setLiveStyle(null);

                return;
            }

            router.put(
                updateAssignment.url({ assignment: bar.id }),
                { planned_join_date: finalStart, planned_leave_date: finalEnd },
                {
                    preserveScroll: true,
                    onSuccess: () => setLiveStyle(null),
                    onError: () => setLiveStyle(null),
                    onFinish: () => setIsDragging(false),
                },
            );
        };

        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
    };

    const computedStyle = liveStyle ?? style;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div
                    ref={containerRef}
                    className={cn(
                        'absolute top-1.5 bottom-1.5 rounded-md',
                        'border border-primary/30 bg-primary/10',
                        'dark:border-primary/40 dark:bg-primary/15',
                        'group/bar flex items-stretch overflow-hidden transition-all',
                        isDragging && 'scale-[1.01] opacity-80 shadow-lg',
                        highlighted && 'ring-2 ring-offset-1 ring-amber-400',
                    )}
                    style={computedStyle}
                >
                    <div
                        className="absolute inset-y-0 left-0 z-20 w-1.5 cursor-ew-resize opacity-0 transition-opacity hover:bg-primary/20 group-hover/bar:opacity-100"
                        onPointerDown={(e) => handlePointerDown(e, 'resize-left')}
                    />
                    <div
                        className="flex min-w-0 flex-1 cursor-grab items-center gap-1.5 px-2 text-xs font-medium text-foreground select-none active:cursor-grabbing"
                        onPointerDown={(e) => handlePointerDown(e, 'move')}
                    >
                        <AssignmentBarPopover.Avatar name={bar.employee_name} size="sm" />
                        <span className="truncate">{bar.employee_name}</span>
                    </div>
                    <div
                        className="absolute inset-y-0 right-0 z-20 w-1.5 cursor-ew-resize opacity-0 transition-opacity hover:bg-primary/20 group-hover/bar:opacity-100"
                        onPointerDown={(e) => handlePointerDown(e, 'resize-right')}
                    />
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className="w-68 overflow-hidden p-0">
                <AssignmentBarPopover
                    bar={bar}
                    can={can}
                    onEdit={onEdit}
                    onDelete={onDelete}
                />
            </PopoverContent>
        </Popover>
    );
}
