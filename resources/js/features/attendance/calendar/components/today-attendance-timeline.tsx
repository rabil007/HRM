import { router, usePoll } from '@inertiajs/react';
import {
    CalendarOff,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    DoorOpen,
    LogIn,
    LogOut,
    Smartphone,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { formatTimelineTime12h } from '../lib/format-timeline-time';
import type {
    TimelineEvent,
    TodayTimeline,
    TodayTimelineStatus,
} from '../types';

function shiftDate(date: string, days: number): string {
    const next = new Date(`${date}T12:00:00`);
    next.setDate(next.getDate() + days);

    const year = next.getFullYear();
    const month = String(next.getMonth() + 1).padStart(2, '0');
    const day = String(next.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function timeToMinutes(time: string): number {
    const parts = time.split(':');
    const h = parseInt(parts[0] ?? '0', 10);
    const m = parseInt(parts[1] ?? '0', 10);

    return h * 60 + m;
}

function timeToPercent(
    time: string,
    windowStart: string,
    windowEnd: string,
): number {
    const start = timeToMinutes(windowStart);
    const end = timeToMinutes(windowEnd);
    const duration = Math.max(1, end - start);
    const minutes = timeToMinutes(time);

    return Math.max(0, Math.min(100, ((minutes - start) / duration) * 100));
}

function nowPercent(windowStart: string, windowEnd: string): number {
    const now = new Date();
    const minutes = now.getHours() * 60 + now.getMinutes();
    const start = timeToMinutes(windowStart);
    const end = timeToMinutes(windowEnd);
    const duration = Math.max(1, end - start);

    return Math.max(0, Math.min(100, ((minutes - start) / duration) * 100));
}

function formatElapsed(minutes: number | null): string | null {
    if (minutes === null) {
        return null;
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    if (h > 0 && m > 0) {
        return `${h}h ${m}m`;
    }

    if (h > 0) {
        return `${h}h`;
    }

    return `${m}m`;
}

function formatSource(source: string | null): string | null {
    if (!source) {
        return null;
    }

    if (source === 'mobile_app') {
        return 'Mobile';
    }

    if (source === 'device') {
        return 'Door';
    }

    if (source === 'correction') {
        return 'Correction';
    }

    return null;
}

function eventTooltip(event: TimelineEvent): string {
    const isCheckIn = event.status === 'checkIn';
    const source = formatSource(event.transaction_source);
    const parts = [
        formatTimelineTime12h(event.time),
        isCheckIn ? 'Check in' : 'Check out',
        event.device_name,
        source,
    ].filter(Boolean);

    return parts.join(' · ');
}

function statusLabel(status: TodayTimelineStatus): string {
    switch (status) {
        case 'checked_in':
            return 'Checked in';
        case 'checked_out':
            return 'Day complete';
        case 'on_leave':
            return 'On leave';
        case 'partial':
            return 'Partial day';
        default:
            return 'No activity';
    }
}

function statusBadgeClass(status: TodayTimelineStatus): string {
    switch (status) {
        case 'checked_in':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
        case 'checked_out':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400';
        case 'on_leave':
            return 'border-violet-500/30 bg-violet-500/10 text-violet-600 dark:text-violet-400';
        case 'partial':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400';
        default:
            return 'border-border/60 bg-muted/40 text-muted-foreground';
    }
}

function TimelineTrack({
    events,
    clockIn,
    clockOut,
    isComplete,
    isToday,
    windowStart,
    windowEnd,
}: {
    events: TimelineEvent[];
    clockIn: string | null;
    clockOut: string | null;
    isComplete: boolean;
    isToday: boolean;
    windowStart: string;
    windowEnd: string;
}) {
    const progressStart = clockIn
        ? timeToPercent(clockIn, windowStart, windowEnd)
        : 0;
    const progressEnd =
        isComplete && clockOut
            ? timeToPercent(clockOut, windowStart, windowEnd)
            : clockIn && isToday
              ? nowPercent(windowStart, windowEnd)
              : clockIn
                ? timeToPercent(windowEnd, windowStart, windowEnd)
                : 0;
    const progressWidth = clockIn
        ? Math.max(0, progressEnd - progressStart)
        : 0;

    return (
        <div className="relative space-y-2 px-1">
            <div className="flex items-center justify-between text-[10px] font-medium tabular-nums text-muted-foreground/70">
                <span>
                    {clockIn ? (
                        <>
                            <span className="text-emerald-500">In</span>{' '}
                            {formatTimelineTime12h(clockIn)}
                        </>
                    ) : (
                        formatTimelineTime12h(windowStart)
                    )}
                </span>
                <span>
                    {clockOut ? (
                        <>
                            <span className="text-amber-500">Out</span>{' '}
                            {formatTimelineTime12h(clockOut)}
                        </>
                    ) : isComplete ? (
                        formatTimelineTime12h(windowEnd)
                    ) : isToday ? (
                        <span className="text-emerald-500">Now</span>
                    ) : (
                        formatTimelineTime12h(windowEnd)
                    )}
                </span>
            </div>

            <div className="relative h-2.5 w-full rounded-full bg-muted/60 dark:bg-white/8">
                {clockIn ? (
                    <div
                        className="absolute inset-y-0 rounded-full bg-linear-to-r from-emerald-500/85 to-emerald-400/65 transition-all duration-700"
                        style={{
                            left: `${progressStart}%`,
                            width: `${progressWidth}%`,
                        }}
                    />
                ) : null}

                {events.map((event, i) => {
                    const isCheckIn = event.status === 'checkIn';
                    const pct = timeToPercent(
                        event.time,
                        windowStart,
                        windowEnd,
                    );

                    return (
                        <button
                            key={`${event.time}-${event.status}-${i}`}
                            type="button"
                            title={eventTooltip(event)}
                            aria-label={eventTooltip(event)}
                            className={cn(
                                'absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-background shadow-sm transition-transform hover:scale-125 focus-visible:scale-125 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                isCheckIn ? 'bg-emerald-500' : 'bg-amber-500',
                            )}
                            style={{
                                left: `${pct}%`,
                                zIndex: events.length - i,
                            }}
                        />
                    );
                })}

                {clockIn && !isComplete && isToday ? (
                    <div
                        className="pointer-events-none absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2"
                        style={{
                            left: `${nowPercent(windowStart, windowEnd)}%`,
                        }}
                    >
                        <div className="relative flex size-4 items-center justify-center">
                            <div className="absolute size-4 animate-ping rounded-full bg-emerald-400/30" />
                            <div className="relative size-2 rounded-full bg-emerald-400 shadow" />
                        </div>
                    </div>
                ) : null}
            </div>

            <div className="flex justify-between text-[9px] tabular-nums text-muted-foreground/45">
                <span>{formatTimelineTime12h(windowStart)}</span>
                <span>{formatTimelineTime12h(windowEnd)}</span>
            </div>
        </div>
    );
}

function EventList({ events }: { events: TimelineEvent[] }) {
    return (
        <div className="divide-y divide-border/50 rounded-xl border border-border/50 bg-muted/20 dark:divide-white/5 dark:border-white/8 dark:bg-white/[0.02]">
            {events.map((event, index) => {
                const isCheckIn = event.status === 'checkIn';
                const source = formatSource(event.transaction_source);
                const SourceIcon =
                    event.transaction_source === 'mobile_app'
                        ? Smartphone
                        : DoorOpen;

                return (
                    <div
                        key={`${event.time}-${event.status}-${index}`}
                        className="flex items-center gap-3 px-3 py-2.5"
                    >
                        <div
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-lg border',
                                isCheckIn
                                    ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-500'
                                    : 'border-amber-500/25 bg-amber-500/10 text-amber-500',
                            )}
                        >
                            {isCheckIn ? (
                                <LogIn className="size-3.5" />
                            ) : (
                                <LogOut className="size-3.5" />
                            )}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-semibold tabular-nums">
                                    {formatTimelineTime12h(event.time)}
                                </span>
                                <span
                                    className={cn(
                                        'text-[10px] font-bold tracking-wide uppercase',
                                        isCheckIn
                                            ? 'text-emerald-500'
                                            : 'text-amber-500',
                                    )}
                                >
                                    {isCheckIn ? 'Check in' : 'Check out'}
                                </span>
                            </div>
                            <p className="truncate text-xs text-muted-foreground">
                                {event.device_name || 'Access point'}
                                {source ? ` · ${source}` : ''}
                            </p>
                        </div>
                        <SourceIcon className="size-3.5 shrink-0 text-muted-foreground/50" />
                    </div>
                );
            })}
        </div>
    );
}

export function TodayAttendanceTimeline({
    timeline,
    year,
    today,
    selectedEmployeeId,
}: {
    timeline: TodayTimeline;
    year: number;
    today: string;
    selectedEmployeeId: number | null;
}) {
    const [historyOpen, setHistoryOpen] = useState(false);

    usePoll(
        60_000,
        { only: ['today_timeline'] },
        { autoStart: timeline?.is_today ?? true },
    );

    if (timeline == null) {
        return null;
    }

    const { events, summary, window_start, window_end, timezone, is_today } =
        timeline;
    const {
        clock_in,
        clock_out,
        is_complete,
        is_on_leave,
        status,
        event_count,
        elapsed_minutes,
    } = summary;
    const elapsed = formatElapsed(elapsed_minutes);
    const canGoForward = timeline.date < today;
    const earliest = shiftDate(today, -365);
    const canGoBack = timeline.date > earliest;

    const navigateDate = (nextDate: string) => {
        const params: {
            year: number;
            employee_id?: number;
            timeline_date?: string;
        } = { year };

        if (selectedEmployeeId !== null) {
            params.employee_id = selectedEmployeeId;
        }

        if (nextDate !== today) {
            params.timeline_date = nextDate;
        }

        router.get('/attendance/calendar', params, {
            only: ['today_timeline'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-5 glass-card">
            <div className="pointer-events-none absolute -top-6 -right-6 size-28 rounded-full bg-emerald-500/10 blur-2xl" />

            <div className="relative mb-3 flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <div className="flex items-center gap-0.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7 rounded-lg"
                                disabled={!canGoBack}
                                onClick={() =>
                                    navigateDate(shiftDate(timeline.date, -1))
                                }
                                title="Previous day"
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <p className="min-w-14 text-center text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                                {is_today ? 'Today' : 'Day'}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7 rounded-lg"
                                disabled={!canGoForward}
                                onClick={() =>
                                    navigateDate(shiftDate(timeline.date, 1))
                                }
                                title="Next day"
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                        <Badge
                            variant="outline"
                            className={cn(
                                'h-5 rounded-md px-1.5 text-[10px] font-semibold',
                                statusBadgeClass(status),
                            )}
                        >
                            {statusLabel(status)}
                        </Badge>
                        {!is_today ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-6 rounded-md px-2 text-[10px] font-semibold"
                                onClick={() => navigateDate(today)}
                            >
                                Jump to today
                            </Button>
                        ) : null}
                    </div>
                    <p className="text-xs text-muted-foreground/70">
                        {formatDisplayDate(timeline.date)}
                        {timezone ? ` · ${timezone.replace(/_/g, ' ')}` : ''}
                        {event_count > 0
                            ? ` · ${event_count} event${event_count === 1 ? '' : 's'}`
                            : ''}
                    </p>
                </div>

                {clock_in || events.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            <LogIn className="size-3 text-emerald-500" />
                            <span className="tabular-nums">
                                {formatTimelineTime12h(clock_in)}
                            </span>
                        </span>
                        <span className="flex items-center gap-1">
                            <LogOut className="size-3 text-amber-500" />
                            <span className="tabular-nums">
                                {formatTimelineTime12h(clock_out)}
                            </span>
                        </span>
                        {elapsed ? (
                            <span className="flex items-center gap-1 font-medium text-foreground/80">
                                <Clock className="size-3" />
                                {elapsed}
                                {!is_complete && is_today ? (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        elapsed
                                    </span>
                                ) : (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        worked
                                    </span>
                                )}
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>

            {is_on_leave && events.length === 0 ? (
                <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                    <CalendarOff className="size-4 shrink-0 text-violet-400" />
                    <span>On approved leave this day</span>
                </div>
            ) : events.length === 0 ? (
                <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                    <Clock className="size-4 shrink-0" />
                    <span>
                        {is_today
                            ? 'No check-ins recorded today'
                            : 'No check-ins recorded for this day'}
                    </span>
                </div>
            ) : (
                <div className="space-y-3">
                    <TimelineTrack
                        events={events}
                        clockIn={clock_in}
                        clockOut={clock_out}
                        isComplete={is_complete}
                        isToday={is_today}
                        windowStart={window_start}
                        windowEnd={window_end}
                    />

                    <Collapsible
                        open={historyOpen}
                        onOpenChange={setHistoryOpen}
                    >
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 w-full justify-between rounded-lg px-2 text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                <span>
                                    {historyOpen ? 'Hide' : 'Show'} check-in
                                    history
                                    <span className="ml-1 text-muted-foreground/70">
                                        ({event_count})
                                    </span>
                                </span>
                                <ChevronDown
                                    className={cn(
                                        'size-3.5 transition-transform duration-200',
                                        historyOpen && 'rotate-180',
                                    )}
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-2">
                            <EventList events={events} />
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            )}
        </div>
    );
}
