import { Card, CardContent } from '@/components/ui/card';
import type {
    DocumentRequirementSummary,
    RequirementStatusFilter,
} from '@/features/organization/documents/shared/types';
import { cn } from '@/lib/utils';

const REQUIREMENT_ITEMS: {
    key: keyof DocumentRequirementSummary;
    status: RequirementStatusFilter;
    label: string;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'required',
        status: 'required',
        label: 'Required',
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'valid',
        status: 'valid',
        label: 'Valid',
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-400',
    },
    {
        key: 'expiring',
        status: 'expiring',
        label: 'Expiring',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
    {
        key: 'expired',
        status: 'expired',
        label: 'Expired',
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
    },
    {
        key: 'missing',
        status: 'missing',
        label: 'Missing',
        cardClass:
            'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
        activeClass: 'border-violet-500/40 ring-1 ring-violet-500/25',
        valueClass: 'text-violet-400',
    },
];

export function DocumentRequirementSummaryCards({
    summary,
    activeStatus,
    onSelect,
}: {
    summary: DocumentRequirementSummary;
    activeStatus: RequirementStatusFilter;
    onSelect: (status: RequirementStatusFilter) => void;
}) {
    return (
        <div className="mb-6">
            <p className="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                Required documents
            </p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5 lg:gap-4">
                {REQUIREMENT_ITEMS.map((item) => {
                    const isActive = item.status === activeStatus;

                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onSelect(item.status)}
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
                                <CardContent className="p-3 sm:p-4">
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
        </div>
    );
}
