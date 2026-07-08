import { Anchor, Building2, Receipt } from 'lucide-react';
import type { ElementType } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PayrollCategory, PayrollHubSummary } from '../types';

type SummaryCategoryFilter = PayrollCategory | '';

const SUMMARY_ITEMS: {
    category: SummaryCategoryFilter;
    title: string;
    hint: (summary: PayrollHubSummary) => string;
    value: (summary: PayrollHubSummary) => number;
    icon: ElementType;
    iconClassName: string;
    cardClassName: string;
    activeClassName: string;
}[] = [
    {
        category: '',
        title: 'Pay runs',
        value: (summary) => summary.total_periods,
        hint: () => 'All payroll periods',
        icon: Receipt,
        iconClassName: 'border-primary/20 bg-primary/10 text-primary',
        cardClassName:
            'border-border/60 hover:border-border dark:border-white/10',
        activeClassName:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
    },
    {
        category: 'crew',
        title: 'Crew',
        value: (summary) => summary.crew_periods,
        hint: (summary) =>
            summary.incomplete_crew_runs > 0
                ? `${summary.incomplete_crew_runs} run${summary.incomplete_crew_runs === 1 ? '' : 's'} need timesheets`
                : 'Timesheet-based payroll',
        icon: Anchor,
        iconClassName:
            'border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-300',
        cardClassName: 'border-border/60 hover:border-sky-500/25',
        activeClassName: 'border-sky-500/40 ring-1 ring-sky-500/25',
    },
    {
        category: 'office',
        title: 'Office',
        value: (summary) => summary.office_periods,
        hint: () => 'Leave-based payroll',
        icon: Building2,
        iconClassName:
            'border-violet-500/20 bg-violet-500/10 text-violet-600 dark:text-violet-300',
        cardClassName: 'border-border/60 hover:border-violet-500/25',
        activeClassName: 'border-violet-500/40 ring-1 ring-violet-500/25',
    },
];

export function PayrollSummaryCards({
    summary,
    activeCategory,
    onSelect,
}: {
    summary: PayrollHubSummary;
    activeCategory: SummaryCategoryFilter;
    onSelect: (category: SummaryCategoryFilter) => void;
}) {
    return (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {SUMMARY_ITEMS.map((item) => {
                const Icon = item.icon;
                const isActive = item.category === activeCategory;

                return (
                    <button
                        key={item.category || 'all'}
                        type="button"
                        onClick={() => onSelect(item.category)}
                        aria-pressed={isActive}
                        className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'relative overflow-hidden glass-card transition-all duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-white/10',
                                item.cardClassName,
                                isActive && item.activeClassName,
                            )}
                        >
                            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-primary/5 via-transparent to-transparent opacity-50" />
                            <CardContent className="relative z-10 flex items-start gap-4 p-5">
                                <div
                                    className={cn(
                                        'flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border shadow-inner',
                                        item.iconClassName,
                                    )}
                                >
                                    <Icon className="h-6 w-6 drop-shadow-sm" />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-[11px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                                        {item.title}
                                    </p>
                                    <p className="mt-1 text-2xl font-extrabold tracking-tight tabular-nums">
                                        {item.value(summary).toLocaleString()}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground/80">
                                        {item.hint(summary)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
