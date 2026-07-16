import { usePoll } from '@inertiajs/react';
import { CalendarOff, Clock, LogIn, LogOut } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { TodayTimeline, TimelineEvent } from '../types';

// Fixed work window: 09:00–18:00 (540 minutes). Dots outside this range are clamped.
const WORK_START_MINUTES = 9 * 60;
const WORK_END_MINUTES = 18 * 60;
const WORK_DURATION_MINUTES = WORK_END_MINUTES - WORK_START_MINUTES;

function timeToMinutes(time: string): number {
    const parts = time.split(':');
    const h = parseInt(parts[0] ?? '0', 10);
    const m = parseInt(parts[1] ?? '0', 10);
    return h * 60 + m;
}

function timeToPercent(time: string): number {
    const minutes = timeToMinutes(time);
    return Math.max(0, Math.min(100, ((minutes - WORK_START_MINUTES) / WORK_DURATION_MINUTES) * 100));
}

function nowPercent(): number {
    const now = new Date();
    const minutes = now.getHours() * 60 + now.getMinutes();
    return Math.max(0, Math.min(100, ((minutes - WORK_START_MINUTES) / WORK_DURATION_MINUTES) * 100));
}

function formatElapsed(clockIn: string, clockOut: string | null): string {
    const startMinutes = timeToMinutes(clockIn);
    let endMinutes: number;

    if (clockOut) {
        endMinutes = timeToMinutes(clockOut);
    } else {
        const now = new Date();
        endMinutes = now.getHours() * 60 + now.getMinutes();
    }

    const elapsed = Math.max(0, endMinutes - startMinutes);
    const h = Math.floor(elapsed / 60);
    const m = elapsed % 60;

    if (h > 0 && m > 0) {
        return `${h}h ${m}m`;
    }

    if (h > 0) {
        return `${h}h`;
    }

    return `${m}m`;
}

function EventDot({ event, index, total }: { event: TimelineEvent; index: number; total: number }) {
    const isCheckIn = event.status === 'checkIn';
    const pct = timeToPercent(event.time);

    // Stagger labels to avoid collision when dots are close together
    const labelOnTop = index % 2 === 0;

    return (
        <div
            className="absolute flex flex-col items-center"
            style={{
                left: `${pct}%`,
                transform: 'translateX(-50%)',
                top: labelOnTop ? '-28px' : '14px',
                zIndex: total - index,
            }}
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
}: {
    events: TimelineEvent[];
    clockIn: string | null;
    clockOut: string | null;
    isComplete: boolean;
}) {
    const progressStart = clockIn ? timeToPercent(clockIn) : 0;
    const progressEnd = isComplete && clockOut ? timeToPercent(clockOut) : clockIn ? nowPercent() : 0;
    const progressWidth = clockIn ? Math.max(0, progressEnd - progressStart) : 0;

    return (
        <div className="relative px-2" style={{ paddingTop: '36px', paddingBottom: '36px' }}>
            {/* Track rail */}
            <div className="relative h-2 w-full overflow-visible rounded-full bg-muted/60 dark:bg-white/8">
                {/* Filled progress band */}
                {clockIn && (
                    <div
                        className="absolute inset-y-0 rounded-full bg-linear-to-r from-emerald-500/80 to-emerald-400/60 transition-all duration-700"
                        style={{
                            left: `${progressStart}%`,
                            width: `${progressWidth}%`,
                        }}
                    />
                )}

                {/* Event dots — rendered relative to the track */}
                {events.map((event, i) => (
                    <EventDot
                        key={`${event.time}-${event.status}-${i}`}
                        event={event}
                        index={i}
                        total={events.length}
                    />
                ))}

                {/* "Now" indicator — only while still checked in */}
                {clockIn && !isComplete && (
                    <div
                        className="absolute -top-1.5 size-5 transition-all duration-700"
                        style={{ left: `${nowPercent()}%`, transform: 'translateX(-50%)' }}
                    >
                        <div className="relative flex items-center justify-center">
                            <div className="size-5 animate-ping rounded-full bg-emerald-400/30" />
                            <div className="absolute size-2.5 rounded-full bg-emerald-400 shadow" />
                        </div>
                    </div>
                )}
            </div>

            {/* Work window labels */}
            <div className="mt-2 flex justify-between">
                <span className="text-[9px] text-muted-foreground/50">09:00</span>
                <span className="text-[9px] text-muted-foreground/50">18:00</span>
            </div>
        </div>
    );
}

export function TodayAttendanceTimeline({ timeline }: { timeline: TodayTimeline }) {
    usePoll(60_000, { only: ['today_timeline'] });

    // null = no linked employee / no Hikvision person — hide entirely
    if (timeline == null) {
        return null;
    }

    const { events, summary } = timeline;
    const { clock_in, clock_out, is_complete, is_on_leave } = summary;

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-5 glass-card">
            {/* Subtle accent glow */}
            <div className="pointer-events-none absolute -top-6 -right-6 size-28 rounded-full bg-emerald-500/10 blur-2xl" />

            {/* Header */}
            <div className="relative mb-1 flex items-center justify-between gap-4">
                <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                    Today
                </p>

                {/* One-line summary */}
                {!is_on_leave && (clock_in || events.length > 0) && (
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            <LogIn className="size-3 text-emerald-500" />
                            <span className="tabular-nums">{clock_in ?? '—'}</span>
                        </span>
                        <span className="flex items-center gap-1">
                            <LogOut className="size-3 text-amber-500" />
                            <span className="tabular-nums">{clock_out ?? '—'}</span>
                        </span>
                        {clock_in && (
                            <span className="flex items-center gap-1 font-medium text-foreground/80">
                                <Clock className="size-3" />
                                {formatElapsed(clock_in, clock_out)}
                                {!is_complete && (
                                    <span className="text-muted-foreground"> elapsed</span>
                                )}
                            </span>
                        )}
                    </div>
                )}
            </div>

            {/* On-leave state */}
            {is_on_leave && events.length === 0 ? (
                <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                    <CalendarOff className="size-4 shrink-0 text-violet-400" />
                    <span>On approved leave today</span>
                </div>
            ) : events.length === 0 ? (
                /* No events yet */
                <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                    <Clock className="size-4 shrink-0" />
                    <span>No check-ins recorded today</span>
                </div>
            ) : (
                /* Timeline track */
                <TimelineTrack
                    events={events}
                    clockIn={clock_in}
                    clockOut={clock_out}
                    isComplete={is_complete}
                />
            )}
        </div>
    );
}
