import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    CreditCard,
    UserX,
    Wallet
    
} from 'lucide-react';
import type {LucideIcon} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import type { BankAccountSummary } from '@/features/organization/bank-accounts/types';
import { cn } from '@/lib/utils';
import { noAccount } from '@/routes/organization/bank-accounts';

type SummaryKey = 'total_bank_accounts' | 'primary_accounts' | 'secondary_accounts' | 'ansari_accounts';

const SUMMARY_ITEMS: {
    key: SummaryKey;
    isPrimaryFilter: string;
    label: string;
    icon: LucideIcon;
    cardClass: string;
    activeClass: string;
    valueClass: string;
    iconClass: string;
}[] = [
    {
        key: 'total_bank_accounts',
        isPrimaryFilter: '',
        label: 'Total bank accounts',
        icon: CreditCard,
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
        iconClass: 'text-muted-foreground',
    },
    {
        key: 'primary_accounts',
        isPrimaryFilter: 'primary',
        label: 'Primary accounts',
        icon: CheckCircle2,
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-400',
        iconClass: 'text-emerald-500/60',
    },
    {
        key: 'secondary_accounts',
        isPrimaryFilter: 'secondary',
        label: 'Secondary accounts',
        icon: Wallet,
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
        iconClass: 'text-sky-500/60',
    },
    {
        key: 'ansari_accounts',
        isPrimaryFilter: 'ansari',
        label: 'Ansari',
        icon: Wallet,
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
        iconClass: 'text-amber-500/60',
    },
];

export function BankAccountsSummaryCards({
    summary,
    activeIsPrimary,
    onSelect,
}: {
    summary: BankAccountSummary;
    activeIsPrimary: string;
    onSelect: (isPrimary: string) => void;
}) {
    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
            {SUMMARY_ITEMS.map((item) => {
                const isActive = item.isPrimaryFilter === activeIsPrimary;
                const Icon = item.icon;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(item.isPrimaryFilter)}
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
            <Link
                href={noAccount.url()}
                className="text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded-xl block"
            >
                <Card className="border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30 cursor-pointer transition-all duration-150">
                    <CardContent className="p-4">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-medium text-muted-foreground leading-tight">
                                No bank account
                            </p>
                            <UserX
                                className="size-3.5 shrink-0 text-violet-500/60"
                                aria-hidden
                            />
                        </div>
                        <p className="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-violet-400">
                            {summary.no_account_employees}
                        </p>
                    </CardContent>
                </Card>
            </Link>
        </div>
    );
}
