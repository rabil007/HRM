import { Card, CardContent } from '@/components/ui/card';
import { LIFECYCLE_FILTER_LABELS } from '@/features/organization/contracts/contracts-format';
import type {
    ContractLifecycleFilter,
    ContractSummary,
} from '@/features/organization/contracts/types';
import { cn } from '@/lib/utils';

type SummaryKey = keyof ContractSummary;

const SUMMARY_ITEMS: {
    key: SummaryKey;
    lifecycle: ContractLifecycleFilter;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'total_contracts',
        lifecycle: 'all',
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'active',
        lifecycle: 'active',
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-400',
    },
    {
        key: 'ending_30',
        lifecycle: 'ending_30',
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
    },
    {
        key: 'ending_60',
        lifecycle: 'ending_60',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
    {
        key: 'ending_90',
        lifecycle: 'ending_90',
        cardClass:
            'border-orange-500/20 bg-orange-500/[0.06] hover:border-orange-500/35',
        activeClass: 'border-orange-500/45 ring-1 ring-orange-500/30',
        valueClass: 'text-orange-400',
    },
    {
        key: 'ended',
        lifecycle: 'ended',
        cardClass: 'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
    },
    {
        key: 'draft',
        lifecycle: 'draft',
        cardClass:
            'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
        activeClass: 'border-violet-500/40 ring-1 ring-violet-500/25',
        valueClass: 'text-violet-400',
    },
];

export function ContractsSummaryCards({
    summary,
    activeLifecycle,
    onSelect,
}: {
    summary: ContractSummary;
    activeLifecycle: ContractLifecycleFilter;
    onSelect: (lifecycle: ContractLifecycleFilter) => void;
}) {
    return (
        <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.lifecycle === activeLifecycle;
                const label =
                    item.lifecycle === 'all'
                        ? 'Total contracts'
                        : LIFECYCLE_FILTER_LABELS[item.lifecycle];

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.lifecycle)}
                        className="text-left"
                    >
                        <Card
                            className={cn(
                                'cursor-pointer transition-all',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <p className="text-xs font-medium text-muted-foreground">
                                    {label}
                                </p>
                                <p
                                    className={cn(
                                        'mt-1 text-2xl font-semibold tabular-nums',
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
