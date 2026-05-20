import { Card, CardContent } from '@/components/ui/card';
import {
    EXPIRY_FILTER_LABELS
    
} from '@/features/organization/documents/document-expiry';
import type {ExpiryFilter} from '@/features/organization/documents/document-expiry';
import { cn } from '@/lib/utils';
import type { DocumentExpirySummary } from './types';

type SummaryKey =
    | 'total_documents'
    | 'expired'
    | 'expiring_30'
    | 'expiring_15'
    | 'expiring_7';

const SUMMARY_ITEMS: {
    key: SummaryKey;
    expiry: ExpiryFilter;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'total_documents',
        expiry: 'all',
        cardClass: 'border-white/5 hover:border-white/10',
        activeClass: 'border-white/20 ring-1 ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'expired',
        expiry: 'expired',
        cardClass: 'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
    },
    {
        key: 'expiring_30',
        expiry: 'expiring_30',
        cardClass: 'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
    },
    {
        key: 'expiring_15',
        expiry: 'expiring_15',
        cardClass: 'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
    {
        key: 'expiring_7',
        expiry: 'expiring_7',
        cardClass: 'border-orange-500/20 bg-orange-500/[0.06] hover:border-orange-500/35',
        activeClass: 'border-orange-500/45 ring-1 ring-orange-500/30',
        valueClass: 'text-orange-400',
    },
];

export function DocumentsSummaryCards({
    summary,
    activeExpiry,
    onSelect,
}: {
    summary: DocumentExpirySummary;
    activeExpiry: ExpiryFilter;
    onSelect: (expiry: ExpiryFilter) => void;
}) {
    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.expiry === activeExpiry;
                const label =
                    item.expiry === 'all'
                        ? 'Total Documents'
                        : EXPIRY_FILTER_LABELS[item.expiry];

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.expiry)}
                        aria-pressed={isActive}
                        className="text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background rounded-xl"
                    >
                        <Card
                            className={cn(
                                'glass-card transition-all duration-200',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground/80">
                                    {label}
                                </p>
                                <p className={cn('mt-1 text-2xl font-bold tabular-nums', item.valueClass)}>
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
