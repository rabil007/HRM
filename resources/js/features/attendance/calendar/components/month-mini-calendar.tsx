import { Link } from '@inertiajs/react';
import type { PointerEvent } from 'react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { buildMonthGrid, getIsoWeekNumber } from '../lib/build-month-grid';
import type { CalendarLeave } from '../types';

const WEEKDAY_LABELS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const FALLBACK_LEAVE_COLOR = '#8b5cf6';

function LeaveDayDetails({ leaves }: { leaves: CalendarLeave[] }) {
    return (
        <div className="space-y-3">
            {leaves.map((leave) => {
                const isPending = leave.status === 'pending';
                const leaveColor =
                    leave.leave_type?.color ?? FALLBACK_LEAVE_COLOR;

                return (
                    <div
                        key={leave.id}
                        className={cn(
                            'space-y-2 rounded-lg border p-3 transition-colors',
                            isPending
                                ? 'border-amber-500/30 bg-amber-500/5 dark:border-amber-400/20 dark:bg-amber-500/10'
                                : 'border-border/60 bg-muted/30 dark:border-white/8 dark:bg-white/5',
                        )}
                    >
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <span
                                    className="size-2.5 shrink-0 rounded-full ring-2 ring-white/10"
                                    style={{ backgroundColor: leaveColor }}
                                />
                                <span className="truncate text-sm font-semibold text-foreground">
                                    {leave.employee?.name ?? 'Unknown employee'}
                                </span>
                            </div>
                            {isPending ? (
                                <Badge
                                    variant="outline"
                                    className="shrink-0 border-amber-500/40 bg-amber-500/10 text-[10px] font-bold text-amber-700 dark:text-amber-300"
                                >
                                    Pending approval
                                </Badge>
                            ) : (
                                <Badge
                                    variant="outline"
                                    className="shrink-0 border-emerald-500/40 bg-emerald-500/10 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"
                                >
                                    Approved
                                </Badge>
                            )}
                        </div>
                        <div className="space-y-1 pl-4 text-xs text-muted-foreground">
                            <div className="font-medium text-foreground/90">
                                {leave.leave_type?.name ?? 'Leave'}
                            </div>
                            <div>
                                {formatDisplayDate(leave.start_date)} —{' '}
                                {formatDisplayDate(leave.end_date)}
                            </div>
                        </div>
                        <Link
                            href={`/attendance/leave-requests/${leave.id}`}
                            className="inline-flex text-xs font-semibold text-primary hover:underline"
                        >
                            View request
                        </Link>
                    </div>
                );
            })}
        </div>
    );
}

function LeaveDayTooltipContent({ leaves }: { leaves: CalendarLeave[] }) {
    return (
        <div className="space-y-2 text-left">
            {leaves.map((leave) => {
                const isPending = leave.status === 'pending';
                const leaveColor =
                    leave.leave_type?.color ?? FALLBACK_LEAVE_COLOR;

                return (
                    <div key={leave.id} className="space-y-0.5">
                        <div className="flex items-center justify-between gap-2 font-semibold">
                            <div className="flex items-center gap-1.5">
                                <span
                                    className="size-2 shrink-0 rounded-full"
                                    style={{ backgroundColor: leaveColor }}
                                />
                                <span>{leave.leave_type?.name ?? 'Leave'}</span>
                            </div>
                            {isPending ? (
                                <span className="text-[10px] font-bold text-amber-600 dark:text-amber-400">
                                    · Pending
                                </span>
                            ) : null}
                        </div>
                        <div className="pl-3.5 text-[11px] text-muted-foreground">
                            {formatDisplayDate(leave.start_date)} —{' '}
                            {formatDisplayDate(leave.end_date)}
                        </div>
                    </div>
                );
            })}
            <p className="text-[10px] text-muted-foreground/80">
                Click for details
            </p>
        </div>
    );
}

function DayCell({
    date,
    day,
    inMonth,
    isToday,
    isWeekend,
    leaves,
    canCreate,
    isInSelection,
    isSelecting,
    onBeginSelection,
    onExtendSelection,
}: {
    date: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    isWeekend: boolean;
    leaves: CalendarLeave[];
    canCreate: boolean;
    isInSelection: boolean;
    isSelecting: boolean;
    onBeginSelection: (date: string) => void;
    onExtendSelection: (date: string) => void;
}) {
    const approvedLeaves = useMemo(
        () => leaves.filter((l) => l.status === 'approved'),
        [leaves],
    );
    const pendingLeaves = useMemo(
        () => leaves.filter((l) => l.status === 'pending'),
        [leaves],
    );
    const hasLeave = leaves.length > 0;
    const hasApproved = approvedLeaves.length > 0;
    const hasPending = pendingLeaves.length > 0;
    const isPendingOnly = hasPending && !hasApproved;

    const primaryColor =
        (hasApproved
            ? approvedLeaves[0]?.leave_type?.color
            : pendingLeaves[0]?.leave_type?.color) ?? FALLBACK_LEAVE_COLOR;
    const showSelectionHighlight = isInSelection && (!hasLeave || isSelecting);

    const cellStyle = useMemo(() => {
        if (!inMonth || !hasLeave || showSelectionHighlight) {
            return undefined;
        }

        if (hasApproved) {
            return {
                backgroundColor: primaryColor,
                boxShadow: `0 4px 14px ${primaryColor}40`,
            };
        }

        return {
            backgroundColor: `${primaryColor}18`,
            backgroundImage: `repeating-linear-gradient(135deg, ${primaryColor}28 0, ${primaryColor}28 2.5px, transparent 2.5px, transparent 6px)`,
            borderColor: primaryColor,
            boxShadow: `0 2px 8px ${primaryColor}20`,
        };
    }, [hasApproved, hasLeave, inMonth, primaryColor, showSelectionHighlight]);

    const cell = (
        <div
            className={cn(
                'relative flex aspect-square w-full max-w-8 items-center justify-center rounded-lg text-[11px] font-semibold transition-all duration-200',
                !inMonth && 'text-muted-foreground/30',
                inMonth &&
                    !hasLeave &&
                    !showSelectionHighlight &&
                    'text-foreground/80 hover:bg-muted/50 dark:hover:bg-white/6',
                inMonth &&
                    isWeekend &&
                    !hasLeave &&
                    !showSelectionHighlight &&
                    'bg-muted/20 dark:bg-white/3',
                inMonth &&
                    hasApproved &&
                    !showSelectionHighlight &&
                    'text-white shadow-sm hover:scale-105 hover:shadow-md',
                inMonth &&
                    isPendingOnly &&
                    !showSelectionHighlight &&
                    'border-2 border-dashed font-bold text-foreground hover:scale-105 hover:shadow-md dark:text-white',
                inMonth &&
                    showSelectionHighlight &&
                    'bg-primary/25 text-primary ring-1 ring-primary/40',
                isToday &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-background',
                canCreate && !hasLeave && 'cursor-cell touch-none select-none',
                hasLeave && !isSelecting && 'cursor-pointer',
            )}
            style={cellStyle}
        >
            {day}
            {isPendingOnly ? (
                <span
                    className="absolute top-0.5 right-0.5 size-1.5 rounded-full ring-1 ring-background"
                    style={{ backgroundColor: primaryColor }}
                />
            ) : null}
            {hasLeave && leaves.length > 1 ? (
                <span className="absolute bottom-0.5 left-1/2 flex -translate-x-1/2 gap-0.5">
                    {leaves.slice(0, 3).map((leave) => (
                        <span
                            key={leave.id}
                            className={cn(
                                'size-1 rounded-full',
                                leave.status === 'pending'
                                    ? 'ring-1 ring-background ring-inset'
                                    : 'bg-white/90',
                            )}
                            style={{
                                backgroundColor:
                                    leave.leave_type?.color ??
                                    FALLBACK_LEAVE_COLOR,
                            }}
                        />
                    ))}
                </span>
            ) : null}
        </div>
    );

    const emptyDaySelectionHandlers = canCreate
        ? {
              onPointerDown: (event: PointerEvent) => {
                  event.preventDefault();
                  onBeginSelection(date);
              },
              onPointerEnter: () => {
                  onExtendSelection(date);
              },
          }
        : {};

    const leaveDayDragExtendHandlers = isSelecting
        ? {
              onPointerEnter: () => {
                  onExtendSelection(date);
              },
          }
        : {};

    if (!hasLeave) {
        return (
            <div className="w-full" {...emptyDaySelectionHandlers}>
                {cell}
            </div>
        );
    }

    if (isSelecting) {
        return (
            <div className="w-full" {...leaveDayDragExtendHandlers}>
                {cell}
            </div>
        );
    }

    return (
        <Popover>
            <Tooltip delayDuration={250}>
                <TooltipTrigger asChild>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="w-full rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {cell}
                        </button>
                    </PopoverTrigger>
                </TooltipTrigger>
                <TooltipContent
                    side="top"
                    align="center"
                    className="max-w-56 p-3"
                >
                    <LeaveDayTooltipContent leaves={leaves} />
                </TooltipContent>
            </Tooltip>
            <PopoverContent side="top" align="center" className="w-64 p-3">
                <LeaveDayDetails leaves={leaves} />
            </PopoverContent>
        </Popover>
    );
}

export function MonthMiniCalendar({
    year,
    month,
    today,
    leaveDayMap,
    canCreate,
    isSelecting,
    isDateInRange,
    onBeginSelection,
    onExtendSelection,
}: {
    year: number;
    month: number;
    today: string;
    leaveDayMap: Map<string, CalendarLeave[]>;
    canCreate: boolean;
    isSelecting: boolean;
    isDateInRange: (date: string) => boolean;
    onBeginSelection: (date: string) => void;
    onExtendSelection: (date: string) => void;
}) {
    const todayDate = useMemo(() => new Date(`${today}T00:00:00`), [today]);
    const isCurrentMonth =
        todayDate.getFullYear() === year && todayDate.getMonth() === month;

    const monthLabel = useMemo(
        () =>
            new Date(year, month, 1).toLocaleString(undefined, {
                month: 'long',
            }),
        [month, year],
    );

    const cells = useMemo(() => buildMonthGrid(year, month), [month, year]);
    const weeks = useMemo(() => {
        const rows: (typeof cells)[] = [];

        for (let index = 0; index < cells.length; index += 7) {
            rows.push(cells.slice(index, index + 7));
        }

        return rows;
    }, [cells]);

    const monthStats = useMemo(() => {
        let approved = 0;
        let pending = 0;

        for (const cell of cells) {
            if (!cell.inMonth) {
                continue;
            }

            const dayLeaves = leaveDayMap.get(cell.date) ?? [];

            if (dayLeaves.some((l) => l.status === 'approved')) {
                approved += 1;
            } else if (dayLeaves.some((l) => l.status === 'pending')) {
                pending += 1;
            }
        }

        return { approved, pending, total: approved + pending };
    }, [cells, leaveDayMap]);

    return (
        <div
            className={cn(
                'rounded-2xl border glass-card p-4 transition-all duration-300 dark:bg-white/4',
                isCurrentMonth
                    ? 'border-primary/30 bg-primary/5 shadow-[0_8px_30px_rgba(99,102,241,0.08)] dark:border-primary/20 dark:bg-primary/5'
                    : 'border-border/60 bg-card/80 hover:border-border dark:border-white/6',
                isSelecting && canCreate && 'select-none',
            )}
        >
            <div className="mb-4 flex items-center justify-between gap-2">
                <div>
                    <div className="text-sm font-extrabold tracking-tight">
                        {monthLabel}
                    </div>
                    <div className="text-[10px] font-semibold tracking-[0.14em] text-muted-foreground/70 uppercase">
                        {year}
                    </div>
                </div>
                {monthStats.total > 0 ? (
                    <div className="flex flex-wrap items-center justify-end gap-1">
                        {monthStats.approved > 0 ? (
                            <Badge
                                variant="secondary"
                                className="rounded-lg bg-muted/50 text-[10px] font-bold tracking-wider uppercase dark:bg-white/8"
                            >
                                {monthStats.approved} day
                                {monthStats.approved === 1 ? '' : 's'}
                            </Badge>
                        ) : null}
                        {monthStats.pending > 0 ? (
                            <Badge
                                variant="outline"
                                className="rounded-lg border-amber-500/40 bg-amber-500/10 text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-300"
                            >
                                {monthStats.pending} pending
                            </Badge>
                        ) : null}
                    </div>
                ) : null}
            </div>

            <div className="grid grid-cols-[1.25rem_repeat(7,minmax(0,1fr))] gap-x-0.5 gap-y-1">
                <div />
                {WEEKDAY_LABELS.map((label, index) => (
                    <div
                        key={`${label}-${index}`}
                        className={cn(
                            'pb-1 text-center text-[10px] font-bold tracking-wider text-muted-foreground/60 uppercase',
                            (index === 0 || index === 6) &&
                                'text-muted-foreground/45',
                        )}
                    >
                        {label}
                    </div>
                ))}
                {weeks.map((week, weekIndex) => {
                    const weekNumber = getIsoWeekNumber(
                        week.find((cell) => cell.inMonth)?.date ?? week[0].date,
                    );

                    return (
                        <div key={`week-${weekIndex}`} className="contents">
                            <div className="flex items-center justify-center text-[10px] font-semibold text-muted-foreground/50 tabular-nums">
                                {weekNumber}
                            </div>
                            {week.map((cell) => {
                                const dayOfWeek = new Date(
                                    `${cell.date}T00:00:00`,
                                ).getDay();

                                return (
                                    <DayCell
                                        key={cell.date}
                                        date={cell.date}
                                        day={cell.day}
                                        inMonth={cell.inMonth}
                                        isToday={
                                            cell.inMonth && cell.date === today
                                        }
                                        isWeekend={
                                            dayOfWeek === 0 || dayOfWeek === 6
                                        }
                                        leaves={
                                            leaveDayMap.get(cell.date) ?? []
                                        }
                                        canCreate={canCreate}
                                        isInSelection={isDateInRange(cell.date)}
                                        isSelecting={isSelecting}
                                        onBeginSelection={onBeginSelection}
                                        onExtendSelection={onExtendSelection}
                                    />
                                );
                            })}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
