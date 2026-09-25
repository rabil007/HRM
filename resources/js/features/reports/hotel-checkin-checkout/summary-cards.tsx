import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    HotelCheckInCheckoutFilters,
    HotelCheckInCheckoutSummary,
} from './types';

const cards = [
    { key: 'all', label: 'All Stays', className: '' },
    {
        key: 'currently_checked_in',
        label: 'Currently Checked In',
        className: 'text-blue-500',
    },
    {
        key: 'check_in_today',
        label: 'Check-In Today',
        className: 'text-emerald-500',
    },
    {
        key: 'checking_out_today',
        label: 'Checking Out Today',
        className: 'text-amber-500',
    },
    {
        key: 'upcoming',
        label: 'Upcoming',
        className: 'text-indigo-500',
    },
    {
        key: 'checked_out',
        label: 'Checked Out',
        className: 'text-slate-500',
    },
] as const;

export function HotelCheckInCheckoutSummaryCards({
    summary,
    filters,
    onSelect,
}: {
    summary: HotelCheckInCheckoutSummary;
    filters: HotelCheckInCheckoutFilters;
    onSelect: (filters: Partial<HotelCheckInCheckoutFilters>) => void;
}) {
    const select = (key: (typeof cards)[number]['key']): void => {
        if (key === 'all') {
            onSelect({ stay_status: '' });

            return;
        }

        onSelect({ stay_status: key });
    };

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            {cards.map((card) => {
                const active =
                    (card.key === 'all' && !filters.stay_status) ||
                    filters.stay_status === card.key;

                const count =
                    card.key === 'all' ? summary.total : summary[card.key];

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
