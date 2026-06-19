import { Ship } from 'lucide-react';
import type { ReactElement } from 'react';
import { useRef } from 'react';
import { cn } from '@/lib/utils';
import { formatIsoDateLocal } from '../lib/planning-gantt-math';
import { useZoom } from '../lib/zoom-context';
import type { GanttBar, GanttVesselGroup, PlanningPagePermissions } from '../types';
import { PlanningGanttRow, RANK_LABEL_WIDTH } from './planning-gantt-row';

type Props = {
    rows: GanttVesselGroup[];
    bars: GanttBar[];
    from: string;
    to: string;
    today: string;
    search: string;
    highlightedRowKey: string | null;
    can: PlanningPagePermissions;
    onRowClick?: (rowKey: string, vesselId: number, rankId: number, estimatedDate: string) => void;
    onEditBar?: (bar: GanttBar) => void;
    onDeleteBar?: (bar: GanttBar) => void;
};

function buildDayColumns(
    from: Date,
    to: Date,
    today: string,
): { date: Date; label: string; isToday: boolean; isWeekend: boolean }[] {
    const cols: { date: Date; label: string; isToday: boolean; isWeekend: boolean }[] = [];
    const cursor = new Date(from);

    while (cursor <= to) {
        const iso = formatIsoDateLocal(cursor);
        const dow = cursor.getDay(); // 0=Sun, 6=Sat

        cols.push({
            date: new Date(cursor),
            label: String(cursor.getDate()),
            isToday: iso === today,
            isWeekend: dow === 0 || dow === 6,
        });
        cursor.setDate(cursor.getDate() + 1);
    }

    return cols;
}

type MonthGroup = { label: string; days: number };

function buildMonthGroups(days: { date: Date }[]): MonthGroup[] {
    const groups: MonthGroup[] = [];
    let current: MonthGroup | null = null;

    for (const day of days) {
        const label = day.date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });

        if (!current || current.label !== label) {
            current = { label, days: 0 };
            groups.push(current);
        }

        current.days++;
    }

    return groups;
}

export function PlanningGantt({
    rows,
    bars,
    from,
    to,
    today,
    search,
    highlightedRowKey,
    can,
    onRowClick,
    onEditBar,
    onDeleteBar,
}: Props): ReactElement {
    const { dayWidth } = useZoom();
    const headerRef = useRef<HTMLDivElement | null>(null);
    const rangeFrom = new Date(`${from}T00:00:00`);
    const rangeTo = new Date(`${to}T23:59:59`);
    const todayDate = new Date(`${today}T00:00:00`);

    const days = buildDayColumns(rangeFrom, new Date(`${to}T00:00:00`), today);
    const monthGroups = buildMonthGroups(days);
    const totalDays = days.length;
    const timelineMinWidth = totalDays * dayWidth;

    const barsByRow = new Map<string, GanttBar[]>();

    for (const bar of bars) {
        const existing = barsByRow.get(bar.row_key) ?? [];
        existing.push(bar);
        barsByRow.set(bar.row_key, existing);
    }

    const lowerSearch = search.toLowerCase();

    if (rows.length === 0) {
        return (
            <div className="flex flex-1 items-center justify-center py-24 text-sm text-muted-foreground">
                No vessel manning configured for the selected filters.
            </div>
        );
    }

    return (
        <div className="flex min-w-0 flex-1 flex-col overflow-auto">
            {/* Timeline header */}
            <div ref={headerRef} className="sticky top-0 z-20 border-b bg-background shadow-sm">
                {/* Month row */}
                <div className="flex">
                    <div
                        className="sticky left-0 z-30 flex shrink-0 items-center border-r bg-background px-3 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60"
                        style={{ width: RANK_LABEL_WIDTH }}
                    >
                        Rank
                    </div>
                    <div className="flex" style={{ minWidth: `${timelineMinWidth}px` }}>
                        {monthGroups.map((group) => (
                            <div
                                key={group.label}
                                className="border-r px-2 py-1.5 text-xs font-semibold text-foreground/70"
                                style={{ width: `${group.days * dayWidth}px`, minWidth: `${group.days * dayWidth}px` }}
                            >
                                {group.label}
                            </div>
                        ))}
                    </div>
                </div>
                {/* Day row */}
                <div className="flex border-t border-border/50">
                    <div
                        className="sticky left-0 z-30 shrink-0 border-r bg-background"
                        style={{ width: RANK_LABEL_WIDTH }}
                    />
                    <div className="flex" style={{ minWidth: `${timelineMinWidth}px` }}>
                        {days.map((day, i) => (
                            <div
                                key={i}
                                {...(day.isToday ? { 'data-today-col': '' } : {})}
                                className={cn(
                                    'flex shrink-0 items-center justify-center border-r py-0.5 text-[10px] transition-colors',
                                    day.isToday &&
                                        'bg-red-500 font-bold text-white',
                                    !day.isToday && day.isWeekend && 'bg-muted/50 text-muted-foreground/50',
                                    !day.isToday && !day.isWeekend && 'text-muted-foreground/60 hover:bg-muted/30',
                                )}
                                style={{ width: `${dayWidth}px`, minWidth: `${dayWidth}px` }}
                            >
                                {day.isToday ? (
                                    <span className="relative flex flex-col items-center leading-none">
                                        <span className="text-[8px] font-normal uppercase opacity-80">
                                            {day.date.toLocaleDateString('en-US', { weekday: 'short' })}
                                        </span>
                                        <span>{day.label}</span>
                                    </span>
                                ) : (
                                    day.label
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Rows */}
            <div>
                {rows.map((vessel) => (
                    <div key={vessel.vessel_id}>
                        {/* Vessel sub-header */}
                        <div
                            className="flex border-b border-border/60 bg-muted/30"
                            style={{ minWidth: timelineMinWidth + RANK_LABEL_WIDTH }}
                        >
                            <div
                                className="sticky left-0 z-10 flex shrink-0 items-center justify-center border-r border-border/60 bg-muted/30"
                                style={{ width: RANK_LABEL_WIDTH, height: 36 }}
                            >
                                <Ship className="h-3.5 w-3.5 text-muted-foreground/40" />
                            </div>
                            <div
                                className="flex flex-1 items-center gap-2 border-l-2 border-l-primary/30 px-3"
                                style={{ minWidth: `${timelineMinWidth}px`, height: 36 }}
                            >
                                <span className="text-[11px] font-bold uppercase tracking-widest text-foreground/60">
                                    {vessel.vessel_name}
                                </span>
                            </div>
                        </div>
                        {vessel.ranks.map((rank) => {
                            const rowBars = barsByRow.get(rank.row_key) ?? [];
                            const isHighlightedRow = highlightedRowKey === rank.row_key;
                            const matchesSearch =
                                lowerSearch !== '' &&
                                rowBars.some((b) =>
                                    b.employee_name.toLowerCase().includes(lowerSearch),
                                );

                            return (
                                <PlanningGanttRow
                                    key={rank.row_key}
                                    rowKey={rank.row_key}
                                    rankName={rank.rank_name}
                                    vesselId={vessel.vessel_id}
                                    rankId={rank.rank_id}
                                    bars={rowBars}
                                    rangeFrom={rangeFrom}
                                    rangeTo={rangeTo}
                                    today={todayDate}
                                    highlightedCrewName={search}
                                    isHighlighted={isHighlightedRow || matchesSearch}
                                    timelineMinWidth={timelineMinWidth}
                                    can={can}
                                    onRowClick={onRowClick}
                                    onEditBar={onEditBar}
                                    onDeleteBar={onDeleteBar}
                                />
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
