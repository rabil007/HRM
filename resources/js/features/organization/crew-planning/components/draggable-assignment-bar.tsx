import { router } from '@inertiajs/react';
import type { PointerEvent, ReactElement } from 'react';
import { useRef, useState } from 'react';

function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
        return 'Ongoing';
    }

    return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
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
import { AssignmentBarActions } from './assignment-bar-actions';

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
    onConfirm?: (bar: GanttBar) => void;
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
    onConfirm,
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
            originalStart: bar.joined_date,
            originalEnd: bar.disembarked_date ?? bar.joined_date,
            containerWidth,
        };
        optimisticStartRef.current = bar.joined_date;
        optimisticEndRef.current = bar.disembarked_date ?? bar.joined_date;

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
                        'border border-dashed border-violet-400/70 bg-violet-50/80',
                        'dark:border-violet-500/60 dark:bg-violet-900/20',
                        'group/bar flex items-stretch overflow-hidden transition-all',
                        isDragging && 'opacity-80 shadow-lg scale-[1.01]',
                        highlighted && 'ring-2 ring-offset-1 ring-amber-400',
                    )}
                    style={computedStyle}
                >
                    {/* Left resize handle */}
                    <div
                        className="absolute inset-y-0 left-0 z-20 w-1.5 cursor-ew-resize opacity-0 transition-opacity hover:bg-violet-400/30 group-hover/bar:opacity-100"
                        onPointerDown={(e) => handlePointerDown(e, 'resize-left')}
                    />

                    {/* Body — drag to move */}
                    <div
                        className="flex min-w-0 flex-1 cursor-grab items-center gap-1.5 px-2 text-xs font-medium text-violet-800 select-none active:cursor-grabbing dark:text-violet-300"
                        onPointerDown={(e) => handlePointerDown(e, 'move')}
                    >
                        <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-violet-200/80 text-[9px] font-bold text-violet-700 dark:bg-violet-700/30 dark:text-violet-300">
                            {initials(bar.employee_name)}
                        </span>
                        <span className="truncate">{bar.employee_name}</span>
                    </div>

                    {/* Right resize handle */}
                    <div
                        className="absolute inset-y-0 right-0 z-20 w-1.5 cursor-ew-resize opacity-0 transition-opacity hover:bg-violet-400/30 group-hover/bar:opacity-100"
                        onPointerDown={(e) => handlePointerDown(e, 'resize-right')}
                    />
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className="w-68 overflow-hidden p-0">
                <div className="flex items-center gap-3 bg-violet-50 px-4 py-3 dark:bg-violet-900/30">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-200 text-sm font-bold text-violet-700 dark:bg-violet-700/40 dark:text-violet-300">
                        {initials(bar.employee_name)}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate font-semibold text-violet-900 dark:text-violet-200">{bar.employee_name}</p>
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-violet-500 dark:text-violet-400">
                            Draft
                        </span>
                    </div>
                </div>
                <div className="space-y-1.5 px-4 py-3 text-xs">
                    {bar.rank_name ? (
                        <div className="flex items-center justify-between gap-4">
                            <span className="text-muted-foreground">Rank</span>
                            <span className="font-medium">{bar.rank_name}</span>
                        </div>
                    ) : null}
                    {bar.vessel_name ? (
                        <div className="flex items-center justify-between gap-4">
                            <span className="text-muted-foreground">Vessel</span>
                            <span className="font-medium">{bar.vessel_name}</span>
                        </div>
                    ) : null}
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">Planned join</span>
                        <span className="font-medium">{formatDate(bar.joined_date)}</span>
                    </div>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">Planned leave</span>
                        <span className="font-medium">{formatDate(bar.disembarked_date)}</span>
                    </div>
                    {bar.notes ? (
                        <p className="mt-1 rounded-md bg-muted px-2 py-1.5 text-muted-foreground">{bar.notes}</p>
                    ) : null}
                </div>
                {(can.update || can.delete || can.confirm) ? (
                    <div className="border-t px-4 pb-3">
                        <AssignmentBarActions
                            bar={bar}
                            can={can}
                            onEdit={onEdit}
                            onDelete={onDelete}
                            onConfirm={onConfirm}
                        />
                    </div>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}
