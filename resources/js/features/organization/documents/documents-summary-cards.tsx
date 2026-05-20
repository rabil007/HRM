import { Card, CardContent } from '@/components/ui/card';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';
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
    label: string;
    expiry: ExpiryFilter;
    accent?: string;
}[] = [
    { key: 'total_documents', label: 'Total Documents', expiry: 'all' },
    { key: 'expired', label: 'Expired', expiry: 'expired', accent: 'text-red-400' },
    { key: 'expiring_30', label: 'Expiring in 30 Days', expiry: 'expiring_30', accent: 'text-sky-400' },
    { key: 'expiring_15', label: 'Expiring in 15 Days', expiry: 'expiring_15', accent: 'text-amber-400' },
    { key: 'expiring_7', label: 'Expiring in 7 Days', expiry: 'expiring_7', accent: 'text-orange-400' },
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

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.expiry)}
                        className="text-left"
                    >
                        <Card
                            className={cn(
                                'glass-card border-white/5 transition-all hover:border-primary/20',
                                isActive && 'border-primary/30 ring-1 ring-primary/20',
                            )}
                        >
                            <CardContent className="p-4">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground/80">
                                    {item.label}
                                </p>
                                <p className={cn('mt-1 text-2xl font-bold tabular-nums', item.accent)}>
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
