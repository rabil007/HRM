import { Card, CardContent } from '@/components/ui/card';
import { TRAINING_EXPIRY_FILTER_LABELS } from '@/features/organization/training/training-expiry';
import type { TrainingExpiryFilter } from '@/features/organization/training/training-expiry';
import type { TrainingExpirySummary } from '@/features/organization/training/types';
import { cn } from '@/lib/utils';

type SummaryKey =
    | 'total'
    | 'expired'
    | 'expiring_30'
    | 'expiring_15'
    | 'expiring_7';

const SUMMARY_ITEMS: {
    key: SummaryKey;
    expiry: TrainingExpiryFilter;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'total',
        expiry: 'all',
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'expired',
        expiry: 'expired',
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
    },
    {
        key: 'expiring_30',
        expiry: 'expiring_30',
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
    },
    {
        key: 'expiring_15',
        expiry: 'expiring_15',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
    {
        key: 'expiring_7',
        expiry: 'expiring_7',
        cardClass:
            'border-orange-500/20 bg-orange-500/[0.06] hover:border-orange-500/35',
        activeClass: 'border-orange-500/45 ring-1 ring-orange-500/30',
        valueClass: 'text-orange-400',
    },
];

export function TrainingSummaryCards({
    summary,
    activeExpiry,
    onSelect,
}: {
    summary: TrainingExpirySummary;
    activeExpiry: TrainingExpiryFilter;
    onSelect: (expiry: TrainingExpiryFilter) => void;
}) {
    return (
        <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.expiry === activeExpiry;
                const label =
                    item.expiry === 'all'
                        ? 'Total Training'
                        : TRAINING_EXPIRY_FILTER_LABELS[item.expiry];

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.expiry)}
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
                                    {label}
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
