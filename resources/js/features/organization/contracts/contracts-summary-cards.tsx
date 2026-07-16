import { Link } from '@inertiajs/react';
import { CheckCircle2, Clock, FileText, UserX, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { LIFECYCLE_FILTER_LABELS } from '@/features/organization/contracts/contracts-format';
import type {
    ContractLifecycleFilter,
    ContractSummary,
} from '@/features/organization/contracts/types';
import { cn } from '@/lib/utils';
import { noContract } from '@/routes/organization/contracts';

type SummaryKey = keyof ContractSummary;

const SUMMARY_ITEMS: {
    key: SummaryKey;
    lifecycle: ContractLifecycleFilter;
    icon: LucideIcon;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    iconClass: string;
    barClass: string;
}[] = [
    {
        key: 'total_contracts',
        lifecycle: 'all',
        icon: FileText,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-2 ring-primary/15 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        iconClass: 'text-muted-foreground',
        barClass: 'bg-primary/30',
    },
    {
        key: 'active',
        lifecycle: 'active',
        icon: CheckCircle2,
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/50 ring-2 ring-emerald-500/20',
        valueClass: 'text-emerald-400',
        iconClass: 'text-emerald-500/60',
        barClass: 'bg-emerald-500/50',
    },
    {
        key: 'ending_30',
        lifecycle: 'ending_30',
        icon: Clock,
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/50 ring-2 ring-sky-500/20',
        valueClass: 'text-sky-400',
        iconClass: 'text-sky-500/60',
        barClass: 'bg-sky-500/50',
    },
    {
        key: 'ending_60',
        lifecycle: 'ending_60',
        icon: Clock,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/50 ring-2 ring-amber-500/20',
        valueClass: 'text-amber-400',
        iconClass: 'text-amber-500/60',
        barClass: 'bg-amber-500/50',
    },
    {
        key: 'ending_90',
        lifecycle: 'ending_90',
        icon: Clock,
        cardClass:
            'border-orange-500/20 bg-orange-500/[0.06] hover:border-orange-500/35',
        activeClass: 'border-orange-500/55 ring-2 ring-orange-500/25',
        valueClass: 'text-orange-400',
        iconClass: 'text-orange-500/60',
        barClass: 'bg-orange-500/50',
    },
    {
        key: 'ended',
        lifecycle: 'ended',
        icon: XCircle,
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/50 ring-2 ring-red-500/20',
        valueClass: 'text-red-400',
        iconClass: 'text-red-500/60',
        barClass: 'bg-red-500/50',
    },
];

function SummaryProgressBar({
    value,
    total,
    barClass,
}: {
    value: number;
    total: number;
    barClass: string;
}) {
    const pct =
        total > 0 ? Math.min(100, Math.round((value / total) * 100)) : 0;

    return (
        <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-border/50">
            <div
                className={cn(
                    'h-full rounded-full transition-all duration-500',
                    barClass,
                )}
                style={{ width: `${pct}%` }}
                aria-hidden
            />
        </div>
    );
}

export function ContractsSummaryCards({
    summary,
    activeLifecycle,
    onSelect,
}: {
    summary: ContractSummary;
    activeLifecycle: ContractLifecycleFilter;
    onSelect: (lifecycle: ContractLifecycleFilter) => void;
}) {
    const total = summary.total_contracts;

    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-7">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.lifecycle === activeLifecycle;
                const label =
                    item.lifecycle === 'all'
                        ? 'Total contracts'
                        : LIFECYCLE_FILTER_LABELS[item.lifecycle];
                const Icon = item.icon;
                const value = summary[item.key] as number;
                // "all" bar fills completely; others show share of total
                const barValue = item.lifecycle === 'all' ? total : value;
                const barTotal = item.lifecycle === 'all' ? total : total;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.lifecycle)}
                        className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'cursor-pointer overflow-hidden transition-all duration-200',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-xs leading-tight font-medium text-muted-foreground">
                                        {label}
                                    </p>
                                    <Icon
                                        className={cn(
                                            'size-4 shrink-0 transition-transform duration-200',
                                            item.iconClass,
                                            isActive && 'scale-110',
                                        )}
                                        aria-hidden
                                    />
                                </div>
                                <p
                                    className={cn(
                                        'mt-2 text-2xl font-semibold tracking-tight tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {value.toLocaleString()}
                                </p>
                                <SummaryProgressBar
                                    value={barValue}
                                    total={barTotal}
                                    barClass={item.barClass}
                                />
                            </CardContent>
                        </Card>
                    </button>
                );
            })}

            {/* No-contract card */}
            <Link
                href={noContract.url()}
                className="block rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <Card className="cursor-pointer overflow-hidden border-violet-500/15 bg-violet-500/[0.04] transition-all duration-200 hover:border-violet-500/30">
                    <CardContent className="p-4">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs leading-tight font-medium text-muted-foreground">
                                No contract
                            </p>
                            <UserX
                                className="size-4 shrink-0 text-violet-500/60"
                                aria-hidden
                            />
                        </div>
                        <p className="mt-2 text-2xl font-semibold tracking-tight text-violet-400 tabular-nums">
                            {summary.no_contract_employees.toLocaleString()}
                        </p>
                        <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-border/50">
                            <div
                                className="h-full rounded-full bg-violet-500/50 transition-all duration-500"
                                style={{ width: '100%' }}
                                aria-hidden
                            />
                        </div>
                    </CardContent>
                </Card>
            </Link>
        </div>
    );
}
