import { Card, CardContent } from '@/components/ui/card';
import type { SeaServiceSummary } from '@/features/organization/sea-services/types';
import type { SeaServiceSummaryFilter } from '@/features/organization/sea-services/use-sea-services-index-filters';
import { cn } from '@/lib/utils';

type SummaryKey = 'total' | 'active';

const SUMMARY_ITEMS: {
    key: SummaryKey;
    filter: SeaServiceSummaryFilter;
    label: string;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'total',
        filter: '',
        label: 'Total Sea Services',
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'active',
        filter: 'active',
        label: 'Open-ended',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
];

export function SeaServicesSummaryCards({
    summary,
    activeFilter,
    onSelect,
}: {
    summary: SeaServiceSummary;
    activeFilter: SeaServiceSummaryFilter;
    onSelect: (filter: SeaServiceSummaryFilter) => void;
}) {
    return (
        <div className="mb-8 grid gap-4 sm:grid-cols-2">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.filter === activeFilter;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.filter)}
                        aria-pressed={isActive}
                        className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'glass-card transition-all duration-200',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                    {item.label}
                                </p>
                                <p
                                    className={cn(
                                        'mt-1 text-2xl font-bold tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {summary[item.key]}
                                </p>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
