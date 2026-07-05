import {
    CheckCircle2,
    Clock,
    FileText,
    UserX,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
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
    icon: LucideIcon;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    iconClass: string;
}[] = [
    {
        key: 'total_contracts',
        lifecycle: 'all',
        icon: FileText,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        iconClass: 'text-muted-foreground',
    },
    {
        key: 'active',
        lifecycle: 'active',
        icon: CheckCircle2,
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-400',
        iconClass: 'text-emerald-500/60',
    },
    {
        key: 'ending_30',
        lifecycle: 'ending_30',
        icon: Clock,
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
        iconClass: 'text-sky-500/60',
    },
    {
        key: 'ending_60',
        lifecycle: 'ending_60',
        icon: Clock,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
        iconClass: 'text-amber-500/60',
    },
    {
        key: 'ending_90',
        lifecycle: 'ending_90',
        icon: Clock,
        cardClass:
            'border-orange-500/20 bg-orange-500/[0.06] hover:border-orange-500/35',
        activeClass: 'border-orange-500/45 ring-1 ring-orange-500/30',
        valueClass: 'text-orange-400',
        iconClass: 'text-orange-500/60',
    },
    {
        key: 'ended',
        lifecycle: 'ended',
        icon: XCircle,
        cardClass: 'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
        iconClass: 'text-red-500/60',
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
        <div className="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-7">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.lifecycle === activeLifecycle;
                const label =
                    item.lifecycle === 'all'
                        ? 'Total contracts'
                        : LIFECYCLE_FILTER_LABELS[item.lifecycle];
                const Icon = item.icon;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.lifecycle)}
                        className="text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-xl"
                    >
                        <Card
                            className={cn(
                                'cursor-pointer transition-all duration-150',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-xs font-medium text-muted-foreground leading-tight">
                                        {label}
                                    </p>
                                    <Icon
                                        className={cn('size-3.5 shrink-0', item.iconClass)}
                                        aria-hidden
                                    />
                                </div>
                                <p
                                    className={cn(
                                        'mt-2 text-2xl font-semibold tabular-nums tracking-tight',
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
            <Card className="border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30 transition-all duration-150">
                <CardContent className="p-4">
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-medium text-muted-foreground leading-tight">
                            No contract
                        </p>
                        <UserX
                            className="size-3.5 shrink-0 text-violet-500/60"
                            aria-hidden
                        />
                    </div>
                    <p className="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-violet-400">
                        {summary.no_contract_employees}
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}
