import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LeaveReportFilters, LeaveReportProps } from './types';

const cards = [
    { key: 'total', label: 'Total Requests', className: '' },
    { key: 'approved', label: 'Approved', className: 'text-emerald-500' },
    { key: 'pending', label: 'Pending', className: 'text-amber-500' },
    {
        key: 'approved_leave_days',
        label: 'Approved Leave Days',
        className: 'text-blue-500',
    },
    {
        key: 'employees_taking_leave',
        label: 'Employees Taking Leave',
        className: 'text-cyan-500',
    },
] as const;

export function LeaveReportSummaryCards({
    summary,
    filters,
    onSelect,
}: {
    summary: LeaveReportProps['summary'];
    filters: LeaveReportFilters;
    onSelect: (filters: Partial<LeaveReportFilters>) => void;
}) {
    const select = (key: (typeof cards)[number]['key']): void => {
        if (key === 'total') {
            onSelect({ status: '' });

            return;
        }

        if (key === 'approved' || key === 'pending') {
            onSelect({ status: key });

            return;
        }

        if (key === 'approved_leave_days' || key === 'employees_taking_leave') {
            onSelect({ status: 'approved' });
        }
    };

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            {cards.map((card) => {
                const active =
                    (card.key === 'total' && !filters.status) ||
                    (card.key === 'approved' &&
                        filters.status === 'approved') ||
                    (card.key === 'pending' && filters.status === 'pending') ||
                    ((card.key === 'approved_leave_days' ||
                        card.key === 'employees_taking_leave') &&
                        filters.status === 'approved');

                const value =
                    card.key === 'approved_leave_days'
                        ? summary.approved_leave_days.toLocaleString(
                              undefined,
                              {
                                  maximumFractionDigits: 2,
                              },
                          )
                        : summary[card.key];

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
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
