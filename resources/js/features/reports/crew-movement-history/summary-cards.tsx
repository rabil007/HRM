import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    CrewMovementHistoryFilters,
    CrewMovementHistoryProps,
} from './types';

const cards = [
    { key: 'total', label: 'Total Assignments', className: '' },
    { key: 'draft', label: 'Draft', className: 'text-slate-500' },
    { key: 'active', label: 'Active', className: 'text-blue-500' },
    { key: 'completed', label: 'Completed', className: 'text-emerald-500' },
    { key: 'cancelled', label: 'Cancelled', className: 'text-rose-500' },
    { key: 'on_vessel', label: 'On Vessel', className: 'text-cyan-500' },
    {
        key: 'needs_attention',
        label: 'Needs Attention',
        className: 'text-amber-500',
    },
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
    const select = (key: (typeof cards)[number]['key']): void => {
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
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            {cards.map((card) => {
                const active =
                    (card.key === 'total' &&
                        !filters.status &&
                        !filters.current_phase &&
                        !filters.needs_attention) ||
                    filters.status === card.key ||
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
                                        'mt-1 text-xl font-bold tabular-nums',
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
    );
}
