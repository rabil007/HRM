import { AlertTriangle, Anchor, ClipboardList, Ship } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import type { CrewAssignmentSummary } from '@/features/organization/crew/types';
import type { CrewSummaryFilter } from '@/features/organization/crew/use-crew-index-filters';
import { cn } from '@/lib/utils';

const SUMMARY_ITEMS: {
    key: CrewSummaryFilter;
    label: string;
    icon: typeof Ship;
    getValue: (summary: CrewAssignmentSummary) => number;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    progressClass: string;
}[] = [
    {
        key: '',
        label: 'Active assignments',
        icon: ClipboardList,
        getValue: (summary) => summary.total,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        progressClass: 'bg-primary',
    },
    {
        key: 'attention',
        label: 'Needs attention',
        icon: AlertTriangle,
        getValue: (summary) => summary.needs_attention,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-500',
        progressClass: 'bg-amber-500',
    },
    {
        key: 'on_vessel',
        label: 'On vessel',
        icon: Ship,
        getValue: (summary) => summary.by_phase.p4 ?? 0,
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-500',
        progressClass: 'bg-emerald-500',
    },
    {
        key: 'pre_mobilisation',
        label: 'Pre-mobilisation',
        icon: Anchor,
        getValue: (summary) => summary.by_phase.p0 ?? 0,
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-500',
        progressClass: 'bg-sky-500',
    },
];

export function CrewSummaryCards({
    summary,
    activeFilter,
    onSelect,
}: {
    summary: CrewAssignmentSummary;
    activeFilter: CrewSummaryFilter;
    onSelect: (filter: CrewSummaryFilter) => void;
}) {
    const onVessel = summary.by_phase.p4 ?? 0;
    const mobilisationPipeline = ['p0', 'p1', 'p2a', 'p2b', 'p3'].reduce(
        (total, phase) => total + (summary.by_phase[phase] ?? 0),
        0,
    );
    const percentage = (value: number): number =>
        summary.total > 0 ? Math.round((value / summary.total) * 100) : 0;

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.key === activeFilter;
                const Icon = item.icon;
                const value = item.getValue(summary);
                const progress =
                    item.key === '' ? percentage(onVessel) : percentage(value);
                const detail =
                    item.key === ''
                        ? `${onVessel} deployed · ${mobilisationPipeline} mobilising`
                        : item.key === 'attention'
                          ? `${percentage(value)}% of assignments require review`
                          : item.key === 'on_vessel'
                            ? `${percentage(value)}% currently deployed`
                            : `${percentage(value)}% awaiting mobilisation`;

                return (
                    <button
                        key={item.key || 'all'}
                        type="button"
                        onClick={() => onSelect(item.key)}
                        aria-pressed={isActive}
                        className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'h-full overflow-hidden glass-card transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4 pb-3">
                                <div className="flex items-start justify-between gap-3">
                                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                        {item.label}
                                    </p>
                                    <Icon
                                        className={cn(
                                            'size-4 shrink-0 opacity-70',
                                            item.valueClass,
                                        )}
                                        aria-hidden
                                    />
                                </div>
                                <p
                                    className={cn(
                                        'mt-2 text-3xl font-bold tracking-tight tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {value}
                                </p>
                                <p className="mt-1 truncate text-[11px] text-muted-foreground/70">
                                    {detail}
                                </p>
                                <div className="mt-3 h-1 overflow-hidden rounded-full bg-muted/50 dark:bg-white/8">
                                    <span
                                        className={cn(
                                            'block h-full rounded-full transition-[width] duration-300',
                                            item.progressClass,
                                        )}
                                        style={{ width: `${progress}%` }}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
