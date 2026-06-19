import { Anchor, Ship, Users } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo } from 'react';
import type { GanttBar, GanttVesselGroup } from '../types';

type Props = {
    rows: GanttVesselGroup[];
    bars: GanttBar[];
};

type StatItem = {
    icon: ReactElement;
    label: string;
    value: number;
    colorClass: string;
};

export function PlanningStatsBar({ rows, bars }: Props): ReactElement {
    const stats = useMemo(() => {
        const totalVessels = rows.length;
        const totalRankSlots = rows.reduce((sum, v) => sum + v.ranks.length, 0);

        /** A bar with a real employee is "assigned"; null employee_id = vacant slot. */
        const assignedBars = bars.filter((b) => b.employee_id !== null).length;
        const vacantBars = bars.filter((b) => b.employee_id === null).length;

        /** Unique employee IDs across all bars. */
        const uniqueCrew = new Set(bars.map((b) => b.employee_id).filter((id) => id !== null)).size;

        return { totalVessels, totalRankSlots, assignedBars, vacantBars, uniqueCrew };
    }, [rows, bars]);

    const items: StatItem[] = [
        {
            icon: <Ship className="h-3.5 w-3.5" />,
            label: 'Vessels',
            value: stats.totalVessels,
            colorClass: 'text-blue-600 dark:text-blue-400',
        },
        {
            icon: <Anchor className="h-3.5 w-3.5" />,
            label: 'Rank slots',
            value: stats.totalRankSlots,
            colorClass: 'text-violet-600 dark:text-violet-400',
        },
        {
            icon: <Users className="h-3.5 w-3.5" />,
            label: 'Assigned',
            value: stats.assignedBars,
            colorClass: 'text-emerald-600 dark:text-emerald-400',
        },
        {
            icon: <Users className="h-3.5 w-3.5" />,
            label: 'Vacant',
            value: stats.vacantBars,
            colorClass: stats.vacantBars > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground/50',
        },
        {
            icon: <Users className="h-3.5 w-3.5" />,
            label: 'Unique crew',
            value: stats.uniqueCrew,
            colorClass: 'text-foreground/70',
        },
    ];

    return (
        <div className="flex items-center gap-0 border-b bg-muted/20 px-4">
            {items.map((item, i) => (
                <div key={item.label} className="flex items-center">
                    {i > 0 && <div className="mx-3 h-4 w-px bg-border/60" />}
                    <div className="flex items-center gap-1.5 py-1.5">
                        <span className={item.colorClass}>{item.icon}</span>
                        <span className="text-[11px] font-bold tabular-nums text-foreground/80">
                            {item.value}
                        </span>
                        <span className="text-[11px] text-muted-foreground/60">{item.label}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}
