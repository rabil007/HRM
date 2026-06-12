import { useMemo, type ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { buildMonthGrid, getIsoWeekNumber } from '../lib/build-month-grid';
import type { CalendarLeave } from '../types';

const WEEKDAY_LABELS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const FALLBACK_LEAVE_COLOR = '#8b5cf6';

function LeaveTooltip({ leaves }: { leaves: CalendarLeave[] }) {
    return (
        <div className="space-y-2 py-0.5">
            {leaves.map((leave) => (
                <div key={leave.id} className="space-y-1">
                    <div className="flex items-center gap-2">
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: leave.leave_type?.color ?? FALLBACK_LEAVE_COLOR }}
                        />
                        <span className="font-semibold">{leave.employee?.name ?? 'Unknown employee'}</span>
                    </div>
                    <div className="pl-5 text-[11px] leading-relaxed text-primary-foreground/85">
                        <div>{leave.leave_type?.name ?? 'Leave'}</div>
                        <div>
                            {formatDisplayDate(leave.start_date)} — {formatDisplayDate(leave.end_date)}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}

function DayCell({
    day,
    inMonth,
    isToday,
    isWeekend,
    leaves,
}: {
    day: number;
    inMonth: boolean;
    isToday: boolean;
    isWeekend: boolean;
    leaves: CalendarLeave[];
}) {
    const hasLeave = leaves.length > 0;
    const primaryColor = leaves[0]?.leave_type?.color ?? FALLBACK_LEAVE_COLOR;

    const cell = (
        <div
            className={cn(
                'relative flex aspect-square w-full max-w-8 items-center justify-center rounded-lg text-[11px] font-semibold transition-all duration-200',
                !inMonth && 'text-muted-foreground/30',
                inMonth && !hasLeave && 'text-foreground/80 hover:bg-muted/50 dark:hover:bg-white/6',
                inMonth && isWeekend && !hasLeave && 'bg-muted/20 dark:bg-white/3',
                inMonth && hasLeave && 'text-white shadow-sm hover:scale-105 hover:shadow-md',
                isToday && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
            )}
            style={
                inMonth && hasLeave
                    ? {
                          backgroundColor: primaryColor,
                          boxShadow: `0 4px 14px ${primaryColor}40`,
                      }
                    : undefined
            }
        >
            {day}
            {hasLeave && leaves.length > 1 ? (
                <span className="absolute bottom-1 left-1/2 flex -translate-x-1/2 gap-0.5">
                    {leaves.slice(0, 3).map((leave) => (
                        <span
                            key={leave.id}
                            className="size-1 rounded-full bg-white/90"
                            style={{ backgroundColor: leave.leave_type?.color ?? FALLBACK_LEAVE_COLOR }}
                        />
                    ))}
                </span>
            ) : null}
        </div>
    );

    if (!hasLeave) {
        return cell;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className="w-full rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {cell}
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs border-border/60 bg-popover px-3 py-2 text-popover-foreground shadow-xl">
                <LeaveTooltip leaves={leaves} />
            </TooltipContent>
        </Tooltip>
    );
}

export function MonthMiniCalendar({
    year,
    month,
    today,
    leaveDayMap,
}: {
    year: number;
    month: number;
    today: string;
    leaveDayMap: Map<string, CalendarLeave[]>;
}) {
    const todayDate = useMemo(() => new Date(`${today}T00:00:00`), [today]);
    const isCurrentMonth = todayDate.getFullYear() === year && todayDate.getMonth() === month;

    const monthLabel = useMemo(
        () => new Date(year, month, 1).toLocaleString(undefined, { month: 'long' }),
        [month, year],
    );

    const cells = useMemo(() => buildMonthGrid(year, month), [month, year]);
    const weeks = useMemo(() => {
        const rows: typeof cells[] = [];

        for (let index = 0; index < cells.length; index += 7) {
            rows.push(cells.slice(index, index + 7));
        }

        return rows;
    }, [cells]);

    const monthLeaveDays = useMemo(() => {
        let count = 0;

        for (const cell of cells) {
            if (cell.inMonth && (leaveDayMap.get(cell.date)?.length ?? 0) > 0) {
                count += 1;
            }
        }

        return count;
    }, [cells, leaveDayMap]);

    return (
        <div
            className={cn(
                'glass-card rounded-2xl border p-4 transition-all duration-300 dark:bg-white/4',
                isCurrentMonth
                    ? 'border-primary/30 bg-primary/5 shadow-[0_8px_30px_rgba(99,102,241,0.08)] dark:border-primary/20 dark:bg-primary/5'
                    : 'border-border/60 bg-card/80 hover:border-border dark:border-white/6',
            )}
        >
            <div className="mb-4 flex items-center justify-between gap-2">
                <div>
                    <div className="text-sm font-extrabold tracking-tight">{monthLabel}</div>
                    <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground/70">
                        {year}
                    </div>
                </div>
                {monthLeaveDays > 0 ? (
                    <Badge
                        variant="secondary"
                        className="rounded-lg bg-muted/50 text-[10px] font-bold uppercase tracking-wider dark:bg-white/8"
                    >
                        {monthLeaveDays} day{monthLeaveDays === 1 ? '' : 's'}
                    </Badge>
                ) : null}
            </div>

            <div className="grid grid-cols-[1.25rem_repeat(7,minmax(0,1fr))] gap-x-0.5 gap-y-1">
                <div />
                {WEEKDAY_LABELS.map((label, index) => (
                    <div
                        key={`${label}-${index}`}
                        className={cn(
                            'pb-1 text-center text-[10px] font-bold uppercase tracking-wider text-muted-foreground/60',
                            (index === 0 || index === 6) && 'text-muted-foreground/45',
                        )}
                    >
                        {label}
                    </div>
                ))}
                {weeks.map((week, weekIndex) => {
                    const weekNumber = getIsoWeekNumber(week.find((cell) => cell.inMonth)?.date ?? week[0].date);

                    return (
                        <div key={`week-${weekIndex}`} className="contents">
                            <div className="flex items-center justify-center text-[10px] font-semibold tabular-nums text-muted-foreground/50">
                                {weekNumber}
                            </div>
                            {week.map((cell) => {
                                const dayOfWeek = new Date(`${cell.date}T00:00:00`).getDay();

                                return (
                                    <DayCell
                                        key={cell.date}
                                        day={cell.day}
                                        inMonth={cell.inMonth}
                                        isToday={cell.date === today}
                                        isWeekend={dayOfWeek === 0 || dayOfWeek === 6}
                                        leaves={leaveDayMap.get(cell.date) ?? []}
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

export function MonthMiniCalendarProvider({ children }: { children: ReactNode }) {
    return <TooltipProvider delayDuration={120}>{children}</TooltipProvider>;
}
