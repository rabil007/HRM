import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { index as crewOperationsIndex } from '@/routes/organization/crew-operations';

type AssignmentSummary = {
    pre_mobilisation: number;
    travel_in: number;
    join_standby: number;
    training: number;
    ready_to_join: number;
    on_vessel: number;
    demob_standby: number;
    home_redeploy: number;
    in_home: number;
    movement_update_required: number;
    total: number;
};

const TOTAL_ITEM = {
    key: 'total',
    label: 'Total',
    cardClass:
        'border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20',
    valueClass: 'text-foreground',
} as const;

const SUMMARY_ITEMS = [
    {
        key: 'movement_update_required',
        label: 'Needs update',
        cardClass:
            'border-red-500/15 bg-red-500/[0.04] hover:border-red-500/30',
        valueClass: 'text-red-400',
    },
    {
        key: 'pre_mobilisation',
        label: 'Pre-mobilisation',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        valueClass: 'text-amber-400',
    },
    {
        key: 'travel_in',
        label: 'Travel in',
        cardClass:
            'border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30',
        valueClass: 'text-violet-400',
    },
    {
        key: 'join_standby',
        label: 'Join standby',
        cardClass:
            'border-orange-500/15 bg-orange-500/[0.04] hover:border-orange-500/30',
        valueClass: 'text-orange-400',
    },
    {
        key: 'training',
        label: 'Training',
        cardClass:
            'border-blue-500/15 bg-blue-500/[0.04] hover:border-blue-500/30',
        valueClass: 'text-blue-400',
    },
    {
        key: 'ready_to_join',
        label: 'Ready to join',
        cardClass:
            'border-cyan-500/15 bg-cyan-500/[0.04] hover:border-cyan-500/30',
        valueClass: 'text-cyan-400',
    },
    {
        key: 'on_vessel',
        label: 'On vessel',
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        valueClass: 'text-emerald-400',
    },
    {
        key: 'demob_standby',
        label: 'Demob standby',
        cardClass:
            'border-pink-500/15 bg-pink-500/[0.04] hover:border-pink-500/30',
        valueClass: 'text-pink-400',
    },
    {
        key: 'home_redeploy',
        label: 'Home redeploy',
        cardClass:
            'border-purple-500/15 bg-purple-500/[0.04] hover:border-purple-500/30',
        valueClass: 'text-purple-400',
    },
    {
        key: 'in_home',
        label: 'In home',
        cardClass:
            'border-teal-500/15 bg-teal-500/[0.04] hover:border-teal-500/30',
        valueClass: 'text-teal-400',
    },
] as const;

function assignmentFilterHref(status: string): string {
    if (status === 'total') {
        return crewOperationsIndex.url();
    }

    return crewOperationsIndex.url({ query: { status } });
}

export function CrewOperationsDeploymentSummaryCards({
    summary,
}: {
    summary: AssignmentSummary;
}): ReactElement {
    const cardClass =
        'min-w-0 flex-1 text-left sm:min-w-[calc(33.333%-0.5rem)] md:min-w-[calc(20%-0.6rem)] lg:min-w-0';

    return (
        <div className="flex flex-wrap gap-3">
            <Link
                key={TOTAL_ITEM.key}
                href={assignmentFilterHref(TOTAL_ITEM.key)}
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
                    href={assignmentFilterHref(item.key)}
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
