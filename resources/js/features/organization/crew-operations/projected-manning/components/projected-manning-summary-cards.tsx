import {
    AlertTriangle,
    CheckCircle2,
    Layers3,
    Ship,
    TrendingDown,
    Users,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import type { ProjectedManningSummary } from '../types';

function SummaryCard({
    label,
    value,
    hint,
    icon: Icon,
    accent,
}: {
    label: string;
    value: string | number;
    hint: string;
    icon: typeof Ship;
    accent: string;
}): ReactElement {
    return (
        <div className="group relative overflow-hidden rounded-2xl border glass-card border-border/60 bg-card/80 p-5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:shadow-md dark:hover:border-white/10">
            <div
                className={cn(
                    'pointer-events-none absolute -top-4 -right-4 size-24 rounded-full opacity-20 blur-2xl transition-opacity group-hover:opacity-30',
                    accent,
                )}
            />
            <div className="relative flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        {label}
                    </p>
                    <p className="text-3xl font-extrabold tracking-tight tabular-nums">
                        {value}
                    </p>
                    <p className="text-xs font-medium text-muted-foreground/75">
                        {hint}
                    </p>
                </div>
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/6">
                    <Icon className="size-5 text-muted-foreground" />
                </div>
            </div>
        </div>
    );
}

export function ProjectedManningSummaryCards({
    summary,
}: {
    summary: ProjectedManningSummary;
}): ReactElement {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <SummaryCard
                label="Positions"
                value={summary.positions}
                hint="Vessel / rank manning rows"
                icon={Ship}
                accent="bg-sky-400"
            />
            <SummaryCard
                label="Current gaps"
                value={summary.current_gap_positions}
                hint="Short at range start"
                icon={AlertTriangle}
                accent="bg-rose-400"
            />
            <SummaryCard
                label="Future gaps"
                value={summary.future_gap_positions}
                hint="Gap opens inside horizon"
                icon={TrendingDown}
                accent="bg-amber-400"
            />
            <SummaryCard
                label="Covered"
                value={summary.covered_positions}
                hint="No projected shortfall"
                icon={CheckCircle2}
                accent="bg-emerald-400"
            />
            <SummaryCard
                label="Overlap"
                value={summary.overlap_positions}
                hint="Projected excess at some point"
                icon={Users}
                accent="bg-violet-400"
            />
            <SummaryCard
                label="Shortfall days"
                value={summary.total_projected_shortfall_days}
                hint="Projected understaffed days"
                icon={Layers3}
                accent="bg-orange-400"
            />
        </div>
    );
}
