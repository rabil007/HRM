import { usePoll } from '@inertiajs/react';
import {
    CalendarOff,
    Clock,
    DoorOpen,
    LogIn,
    LogOut,
    Smartphone,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type {
    TimelineEvent,
    TodayTimeline,
    TodayTimelineStatus,
} from '../types';

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

function EventDot({
    event,
    index,
    total,
    windowStart,
    windowEnd,
}: {
    event: TimelineEvent;
    index: number;
    total: number;
    windowStart: string;
    windowEnd: string;
}) {
    const isCheckIn = event.status === 'checkIn';
    const pct = timeToPercent(event.time, windowStart, windowEnd);
    const labelOnTop = index % 2 === 0;
    const source = formatSource(event.transaction_source);
    const detail = [event.device_name, source].filter(Boolean).join(' · ');

    return (
        <div
            className="absolute flex flex-col items-center"
            style={{
                left: `${pct}%`,
                transform: 'translateX(-50%)',
                top: labelOnTop ? '-40px' : '14px',
                zIndex: total - index,
            }}
            title={detail || undefined}
        >
            {labelOnTop && (
                <div className="mb-1 flex flex-col items-center gap-0.5">
                    <span className="text-[10px] font-semibold tabular-nums text-muted-foreground">
                        {event.time}
                    </span>
                    <span
                        className={cn(
                            'text-[9px] font-bold tracking-wide uppercase',
                            isCheckIn ? 'text-emerald-500' : 'text-amber-500',
                        )}
                    >
                        {isCheckIn ? 'In' : 'Out'}
                    </span>
                    {detail ? (
                        <span className="max-w-24 truncate text-[9px] text-muted-foreground/70">
                            {detail}
                        </span>
                    ) : null}
                </div>
            )}
            <div
                className={cn(
                    'size-3 rounded-full border-2 border-background shadow-sm',
                    isCheckIn ? 'bg-emerald-500' : 'bg-amber-500',
                )}
            />
            {!labelOnTop && (
                <div className="mt-1 flex flex-col items-center gap-0.5">
                    <span className="text-[10px] font-semibold tabular-nums text-muted-foreground">
                        {event.time}
                    </span>
                    <span
                        className={cn(
                            'text-[9px] font-bold tracking-wide uppercase',
                            isCheckIn ? 'text-emerald-500' : 'text-amber-500',
                        )}
                    >
                        {isCheckIn ? 'In' : 'Out'}
                    </span>
                    {detail ? (
                        <span className="max-w-24 truncate text-[9px] text-muted-foreground/70">
                            {detail}
                        </span>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function TimelineTrack({
    events,
    clockIn,
    clockOut,
    isComplete,
    windowStart,
    windowEnd,
}: {
    events: TimelineEvent[];
    clockIn: string | null;
    clockOut: string | null;
    isComplete: boolean;
    windowStart: string;
    windowEnd: string;
}) {
    const progressStart = clockIn
        ? timeToPercent(clockIn, windowStart, windowEnd)
        : 0;
    const progressEnd =
        isComplete && clockOut
            ? timeToPercent(clockOut, windowStart, windowEnd)
            : clockIn
              ? nowPercent(windowStart, windowEnd)
              : 0;
    const progressWidth = clockIn
        ? Math.max(0, progressEnd - progressStart)
        : 0;

    return (
        <div
            className="relative px-2"
            style={{ paddingTop: '52px', paddingBottom: '52px' }}
        >
            <div className="relative h-2 w-full overflow-visible rounded-full bg-muted/60 dark:bg-white/8">
                {clockIn ? (
                    <div
                        className="absolute inset-y-0 rounded-full bg-linear-to-r from-emerald-500/80 to-emerald-400/60 transition-all duration-700"
                        style={{
                            left: `${progressStart}%`,
                            width: `${progressWidth}%`,
                        }}
                    />
                ) : null}

                {events.map((event, i) => (
                    <EventDot
                        key={`${event.time}-${event.status}-${i}`}
                        event={event}
                        index={i}
                        total={events.length}
                        windowStart={windowStart}
                        windowEnd={windowEnd}
                    />
                ))}

                {clockIn && !isComplete ? (
                    <div
                        className="absolute -top-1.5 size-5 transition-all duration-700"
                        style={{
                            left: `${nowPercent(windowStart, windowEnd)}%`,
                            transform: 'translateX(-50%)',
                        }}
                    >
                        <div className="relative flex items-center justify-center">
                            <div className="size-5 animate-ping rounded-full bg-emerald-400/30" />
                            <div className="absolute size-2.5 rounded-full bg-emerald-400 shadow" />
                        </div>
                    </div>
                ) : null}
            </div>

            <div className="mt-2 flex justify-between">
                <span className="text-[9px] tabular-nums text-muted-foreground/50">
                    {windowStart}
                </span>
                <span className="text-[9px] tabular-nums text-muted-foreground/50">
                    {windowEnd}
                </span>
            </div>
        </div>
    );
}

function EventList({ events }: { events: TimelineEvent[] }) {
    return (
        <div className="mt-1 divide-y divide-border/50 rounded-xl border border-border/50 bg-muted/20 dark:divide-white/5 dark:border-white/8 dark:bg-white/[0.02]">
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
                                    {event.time}
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
}: {
    timeline: TodayTimeline;
}) {
    usePoll(60_000, { only: ['today_timeline'] });

    if (timeline == null) {
        return null;
    }

    const { events, summary, window_start, window_end, timezone } = timeline;
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

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-5 glass-card">
            <div className="pointer-events-none absolute -top-6 -right-6 size-28 rounded-full bg-emerald-500/10 blur-2xl" />

            <div className="relative mb-3 flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                            Today
                        </p>
                        <Badge
                            variant="outline"
                            className={cn(
                                'h-5 rounded-md px-1.5 text-[10px] font-semibold',
                                statusBadgeClass(status),
                            )}
                        >
                            {statusLabel(status)}
                        </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground/70">
                        {timeline.date}
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
                                {clock_in ?? '—'}
                            </span>
                        </span>
                        <span className="flex items-center gap-1">
                            <LogOut className="size-3 text-amber-500" />
                            <span className="tabular-nums">
                                {clock_out ?? '—'}
                            </span>
                        </span>
                        {elapsed ? (
                            <span className="flex items-center gap-1 font-medium text-foreground/80">
                                <Clock className="size-3" />
                                {elapsed}
                                {!is_complete ? (
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
                    <span>On approved leave today</span>
                </div>
            ) : events.length === 0 ? (
                <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                    <Clock className="size-4 shrink-0" />
                    <span>No check-ins recorded today</span>
                </div>
            ) : (
                <div className="space-y-3">
                    <TimelineTrack
                        events={events}
                        clockIn={clock_in}
                        clockOut={clock_out}
                        isComplete={is_complete}
                        windowStart={window_start}
                        windowEnd={window_end}
                    />
                    <EventList events={events} />
                </div>
            )}
        </div>
    );
}
