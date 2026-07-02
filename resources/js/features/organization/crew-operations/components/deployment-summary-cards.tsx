import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import type { DeploymentSummary } from '@/features/organization/crew-deployments/types';
import { cn } from '@/lib/utils';
import { index as crewDeploymentsIndex } from '@/routes/organization/crew-deployments';

const TOTAL_ITEM = {
    key: 'total',
    label: 'Total',
    cardClass:
        'border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20',
    valueClass: 'text-foreground',
} as const;

const SUMMARY_ITEMS = [
    {
        key: 'unknown',
        label: 'Needs update',
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        valueClass: 'text-red-400',
    },
    {
        key: 'arrived',
        label: 'Arrived',
        cardClass:
            'border-sky-500/15 bg-sky-500/[0.04] hover:border-sky-500/30',
        valueClass: 'text-sky-400',
    },
    {
        key: 'join_standby',
        label: 'Join standby',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        valueClass: 'text-amber-400',
    },
    {
        key: 'on_vessel',
        label: 'On vessel',
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        valueClass: 'text-emerald-400',
    },
    {
        key: 'disembarked',
        label: 'Disembarked',
        cardClass:
            'border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20',
        valueClass: 'text-foreground',
    },
    {
        key: 'leave_standby',
        label: 'Leave standby',
        cardClass:
            'border-orange-500/15 bg-orange-500/[0.04] hover:border-orange-500/30',
        valueClass: 'text-orange-400',
    },
    {
        key: 'travel',
        label: 'Travel',
        cardClass:
            'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
        valueClass: 'text-violet-400',
    },
    {
        key: 'in_home',
        label: 'In home',
        cardClass:
            'border-teal-500/15 bg-teal-500/[0.04] hover:border-teal-500/30',
        valueClass: 'text-teal-400',
    },
] as const;

function deploymentFilterHref(status: string): string {
    if (status === 'total') {
        return crewDeploymentsIndex.url();
    }

    return crewDeploymentsIndex.url({ query: { status } });
}

export function CrewOperationsDeploymentSummaryCards({
    summary,
}: {
    summary: DeploymentSummary;
}): ReactElement {
    const cardClass =
        'min-w-0 flex-1 text-left sm:min-w-[calc(33.333%-0.5rem)] md:min-w-[calc(20%-0.6rem)] lg:min-w-0';

    return (
        <div className="flex flex-wrap gap-3">
            <Link
                key={TOTAL_ITEM.key}
                href={deploymentFilterHref(TOTAL_ITEM.key)}
                className={cardClass}
            >
                <Card
                    className={cn(
                        'h-full w-full transition-colors',
                        TOTAL_ITEM.cardClass,
                    )}
                >
                    <CardContent className="p-4">
                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase">
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
            </Link>
            {SUMMARY_ITEMS.map((item) => (
                <Link
                    key={item.key}
                    href={deploymentFilterHref(item.key)}
                    className={cardClass}
                >
                    <Card
                        className={cn(
                            'h-full w-full transition-colors',
                            item.cardClass,
                        )}
                    >
                        <CardContent className="p-4">
                            <p className="text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase">
                                {item.label}
                            </p>
                            <p
                                className={cn(
                                    'mt-1 text-2xl font-bold tabular-nums',
                                    item.valueClass,
                                )}
                            >
                                {summary[item.key] ?? 0}
                            </p>
                        </CardContent>
                    </Card>
                </Link>
            ))}
        </div>
    );
}
