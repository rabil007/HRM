import type { ReactElement } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { DeploymentSummary } from '@/features/organization/crew-deployments/types';

const TOTAL_ITEM = {
    key: 'total',
    label: 'Total',
    cardClass: 'border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20',
    activeClass: 'border-primary/30 ring-1 ring-primary/10',
    valueClass: 'text-foreground',
} as const;

const SUMMARY_ITEMS = [
    {
        key: 'on_vessel',
        label: 'On vessel',
        cardClass: 'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-400',
    },
    {
        key: 'join_standby',
        label: 'Join standby',
        cardClass: 'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-400',
    },
    {
        key: 'leave_standby',
        label: 'Leave standby',
        cardClass: 'border-orange-500/15 bg-orange-500/[0.04] hover:border-orange-500/30',
        activeClass: 'border-orange-500/40 ring-1 ring-orange-500/25',
        valueClass: 'text-orange-400',
    },
    {
        key: 'awaiting_join',
        label: 'Awaiting join',
        cardClass: 'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        activeClass: 'border-sky-500/40 ring-1 ring-sky-500/25',
        valueClass: 'text-sky-400',
    },
    {
        key: 'travel',
        label: 'Travel',
        cardClass: 'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
        activeClass: 'border-violet-500/40 ring-1 ring-violet-500/25',
        valueClass: 'text-violet-400',
    },
    {
        key: 'disembarked',
        label: 'Disembarked',
        cardClass: 'border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20',
        activeClass: 'border-foreground/20 ring-1 ring-foreground/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'unknown',
        label: 'Needs update',
        cardClass: 'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        activeClass: 'border-red-500/40 ring-1 ring-red-500/25',
        valueClass: 'text-red-400',
    },
] as const;

export function CrewDeploymentsSummaryCards({
    summary,
    activeStatus,
    hasActiveFilters,
    onSelect,
    onClearFilters,
}: {
    summary: DeploymentSummary;
    activeStatus: string;
    hasActiveFilters: boolean;
    onSelect: (status: string) => void;
    onClearFilters: () => void;
}): ReactElement {
    const isTotalActive = !hasActiveFilters;

    return (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
            <button
                key={TOTAL_ITEM.key}
                type="button"
                onClick={onClearFilters}
                className="text-left"
            >
                <Card
                    className={cn(
                        'h-full transition-colors',
                        TOTAL_ITEM.cardClass,
                        isTotalActive && TOTAL_ITEM.activeClass,
                    )}
                >
                    <CardContent className="p-4">
                        <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/60">
                            {TOTAL_ITEM.label}
                        </p>
                        <p
                            className={cn(
                                'mt-1 text-2xl font-bold tabular-nums',
                                TOTAL_ITEM.valueClass,
                            )}
                        >
                            {summary.total ?? 0}
                        </p>
                    </CardContent>
                </Card>
            </button>
            {SUMMARY_ITEMS.map((item) => {
                const isActive = activeStatus === item.key;
                const count = summary[item.key] ?? 0;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelect(isActive ? '' : item.key)}
                        className="text-left"
                    >
                        <Card
                            className={cn(
                                'h-full transition-colors',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-4">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/60">
                                    {item.label}
                                </p>
                                <p
                                    className={cn(
                                        'mt-1 text-2xl font-bold tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {count}
                                </p>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
