import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    CrewMovementHistoryFilters,
    CrewMovementHistoryProps,
} from './types';

const primaryCards = [
    { key: 'total', label: 'Total Assignments', className: '' },
    {
        key: 'on_vessel',
        label: 'Currently On Vessel',
        className: 'text-cyan-500',
    },
    { key: 'completed', label: 'Completed', className: 'text-emerald-500' },
    {
        key: 'needs_attention',
        label: 'Needs Attention',
        className: 'text-amber-500',
    },
] as const;

const secondaryStatuses = [
    { key: 'draft', label: 'Draft', color: 'text-slate-500' },
    { key: 'active', label: 'Active', color: 'text-blue-500' },
    { key: 'cancelled', label: 'Cancelled', color: 'text-rose-500' },
] as const;

export function CrewMovementHistorySummaryCards({
    summary,
    filters,
    onSelect,
}: {
    summary: CrewMovementHistoryProps['summary'];
    filters: CrewMovementHistoryFilters;
    onSelect: (filters: Partial<CrewMovementHistoryFilters>) => void;
}) {
    const select = (key: string): void => {
        if (key === 'total') {
            onSelect({ status: '', current_phase: '', needs_attention: '' });

            return;
        }

        if (key === 'on_vessel') {
            onSelect({
                status: '',
                current_phase: 'p4',
                needs_attention: '',
            });

            return;
        }

        if (key === 'needs_attention') {
            onSelect({
                status: '',
                current_phase: '',
                needs_attention: '1',
            });

            return;
        }

        onSelect({
            status: key,
            current_phase: '',
            needs_attention: '',
        });
    };

    return (
        <div className="space-y-2.5">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {primaryCards.map((card) => {
                    const active =
                        (card.key === 'total' &&
                            !filters.status &&
                            !filters.current_phase &&
                            !filters.needs_attention) ||
                        (card.key === 'completed' &&
                            filters.status === 'completed' &&
                            !filters.current_phase &&
                            !filters.needs_attention) ||
                        (card.key === 'on_vessel' &&
                            filters.current_phase === 'p4') ||
                        (card.key === 'needs_attention' &&
                            filters.needs_attention === '1');

                    return (
                        <button
                            key={card.key}
                            type="button"
                            onClick={() => select(card.key)}
                            aria-pressed={active}
                            className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <Card
                                className={cn(
                                    'h-full transition-colors',
                                    active && 'border-primary/40 bg-primary/5',
                                )}
                            >
                                <CardContent className="p-3">
                                    <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        {card.label}
                                    </p>
                                    <p
                                        className={cn(
                                            'mt-1 text-2xl font-bold tabular-nums',
                                            card.className,
                                        )}
                                    >
                                        {summary[card.key]}
                                    </p>
                                </CardContent>
                            </Card>
                        </button>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                <span className="text-[11px] font-medium text-muted-foreground">
                    Filter by status:
                </span>
                {secondaryStatuses.map((item) => {
                    const active =
                        filters.status === item.key &&
                        !filters.current_phase &&
                        !filters.needs_attention;

                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => select(item.key)}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition-colors hover:bg-muted/60',
                                active
                                    ? 'border-primary/40 bg-primary/10 text-primary'
                                    : 'border-border/60 bg-muted/20 text-foreground',
                            )}
                        >
                            <span>{item.label}</span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    item.color,
                                )}
                            >
                                {summary[item.key]}
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
