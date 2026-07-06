import {
    Banknote,
    Building2,
    CreditCard,
    UserX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import type { NoBankAccountSummary } from '@/features/organization/bank-accounts/types';
import { cn } from '@/lib/utils';

type SummaryKey = 'total_no_account' | 'bank_transfer' | 'cash_c3' | 'cash_other';

const SUMMARY_ITEMS: {
    key: SummaryKey;
    filterValue: string;
    label: string;
    icon: LucideIcon;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    iconClass: string;
}[] = [
    {
        key: 'total_no_account',
        filterValue: '',
        label: 'Total without account',
        icon: UserX,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        iconClass: 'text-muted-foreground',
    },
    {
        key: 'bank_transfer',
        filterValue: 'bank_transfer',
        label: 'Bank transfer',
        icon: Building2,
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
        iconClass: 'text-sky-500/60',
    },
    {
        key: 'cash_c3',
        filterValue: 'cash_c3',
        label: 'C3',
        icon: CreditCard,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
        iconClass: 'text-amber-500/60',
    },
    {
        key: 'cash_other',
        filterValue: 'cash_other',
        label: 'Cash',
        icon: Banknote,
        cardClass:
            'border-teal-500/15 bg-teal-500/[0.04] hover:border-teal-500/30',
        activeClass: 'border-teal-500/40 ring-1 ring-teal-500/25',
        valueClass: 'text-teal-400',
        iconClass: 'text-teal-500/60',
    },
];

export function NoAccountSummaryCards({
    summary,
    activeFilter,
    onSelect,
}: {
    summary: NoBankAccountSummary;
    activeFilter: string;
    onSelect: (filterValue: string) => void;
}) {
    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-4">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.filterValue === (activeFilter || '');
                const Icon = item.icon;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.filterValue)}
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
                                        {item.label}
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
        </div>
    );
}
