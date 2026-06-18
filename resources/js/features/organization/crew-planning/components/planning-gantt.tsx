import { useRef } from 'react';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { PlanningGanttRow, ROW_HEIGHT } from './planning-gantt-row';
import type { GanttBar, GanttVesselGroup, PlanningPagePermissions } from '../types';

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
    onConfirmBar?: (bar: GanttBar) => void;
    onRowVisible?: (rowKey: string) => void;
};

function buildDayColumns(from: Date, to: Date): { date: Date; label: string; isToday: boolean }[] {
    const cols: { date: Date; label: string; isToday: boolean }[] = [];
    const todayStr = new Date().toDateString();
    const cursor = new Date(from);

    while (cursor <= to) {
        cols.push({
            date: new Date(cursor),
            label: String(cursor.getDate()),
            isToday: cursor.toDateString() === todayStr,
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
    onConfirmBar,
    onRowVisible,
}: Props): ReactElement {
    const rangeFrom = new Date(`${from}T00:00:00`);
    const rangeTo = new Date(`${to}T23:59:59`);
    const todayDate = new Date(`${today}T00:00:00`);

    const days = buildDayColumns(rangeFrom, new Date(`${to}T00:00:00`));
    const monthGroups = buildMonthGroups(days);
    const totalDays = days.length;

    const rowRefs = useRef<Map<string, React.RefObject<HTMLDivElement | null>>>(new Map());

    function getRowRef(rowKey: string): React.RefObject<HTMLDivElement | null> {
        if (!rowRefs.current.has(rowKey)) {
            rowRefs.current.set(rowKey, { current: null });
        }
        return rowRefs.current.get(rowKey)!;
    }

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
            <div className="sticky top-0 z-20 border-b bg-background">
                {/* Month row */}
                <div className="flex" style={{ minWidth: `${totalDays * 32}px` }}>
                    {monthGroups.map((group) => (
                        <div
                            key={group.label}
                            className="border-r px-2 py-1 text-xs font-semibold text-muted-foreground"
                            style={{ width: `${group.days * 32}px`, minWidth: `${group.days * 32}px` }}
                        >
                            {group.label}
                        </div>
                    ))}
                </div>
                {/* Day row */}
                <div className="flex border-t" style={{ minWidth: `${totalDays * 32}px` }}>
                    {days.map((day, i) => (
                        <div
                            key={i}
                            className={cn(
                                'flex w-8 min-w-8 shrink-0 items-center justify-center border-r py-0.5 text-[10px]',
                                day.isToday && 'bg-red-50 font-bold text-red-600 dark:bg-red-950/30',
                                !day.isToday && 'text-muted-foreground',
                            )}
                        >
                            {day.label}
                        </div>
                    ))}
                </div>
            </div>

            {/* Rows */}
            <div style={{ minWidth: `${totalDays * 32}px` }}>
                {rows.map((vessel) => (
                    <div key={vessel.vessel_id}>
                        {/* Vessel sub-header */}
                        <div className="sticky left-0 z-10 border-b bg-muted/40 px-3 py-1.5">
                            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {vessel.vessel_name}
                            </span>
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
                                    rowRef={getRowRef(rank.row_key)}
                                    can={can}
                                    onRowClick={onRowClick}
                                    onEditBar={onEditBar}
                                    onDeleteBar={onDeleteBar}
                                    onConfirmBar={onConfirmBar}
                                />
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
