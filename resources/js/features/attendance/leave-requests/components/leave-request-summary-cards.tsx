import { Ban, CheckCircle2, Clock, ListChecks, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LeaveRequestStatus } from '../types';

export type LeaveRequestStatusCounts = {
    all: number;
    pending: number;
    approved: number;
    rejected: number;
    cancelled: number;
};

type StatusFilterValue = '' | LeaveRequestStatus;

const SUMMARY_ITEMS: {
    value: StatusFilterValue;
    label: string;
    countKey: keyof LeaveRequestStatusCounts;
    icon: LucideIcon;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    iconClass: string;
}[] = [
    {
        value: '',
        label: 'All',
        countKey: 'all',
        icon: ListChecks,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        iconClass: 'text-muted-foreground',
    },
    {
        value: 'pending',
        label: 'Pending',
        countKey: 'pending',
        icon: Clock,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-600 dark:text-amber-400',
        iconClass: 'text-amber-500/60',
    },
    {
        value: 'approved',
        label: 'Approved',
        countKey: 'approved',
        icon: CheckCircle2,
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-600 dark:text-emerald-400',
        iconClass: 'text-emerald-500/60',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        countKey: 'rejected',
        icon: XCircle,
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-600 dark:text-red-400',
        iconClass: 'text-red-500/60',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        countKey: 'cancelled',
        icon: Ban,
        cardClass:
            'border-muted-foreground/15 bg-muted/30 hover:border-muted-foreground/30 dark:bg-white/[0.03]',
        activeClass:
            'border-muted-foreground/40 ring-1 ring-muted-foreground/25',
        valueClass: 'text-muted-foreground',
        iconClass: 'text-muted-foreground/60',
    },
];

export function LeaveRequestSummaryCards({
    counts,
    activeStatus,
    onSelect,
}: {
    counts: LeaveRequestStatusCounts;
    activeStatus: string;
    onSelect: (status: StatusFilterValue) => void;
}) {
    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = activeStatus === item.value;
                const Icon = item.icon;
                const value = counts[item.countKey];

                return (
                    <button
                        key={item.countKey}
                        type="button"
                        onClick={() => onSelect(item.value)}
                        aria-pressed={isActive}
                        aria-label={`Show ${item.label.toLowerCase()} leave requests`}
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
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                        {item.label}
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
                                        'mt-2 text-2xl font-bold tracking-tight tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {value.toLocaleString()}
                                </p>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
