import {
    AlertTriangle,
    ArrowUpRight,
    CalendarClock,
    ClipboardList,
    Users,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RequirementSummaryCardsData } from '@/types/recruitment';

type Props = {
    summary: RequirementSummaryCardsData;
    activeCount: number;
    selectedView: 'all' | 'overdue' | 'due_soon' | null;
    onSelect: (deadlineHealth: 'overdue' | 'due_soon' | null) => void;
};

export function RequirementSummaryCards({
    summary,
    activeCount,
    selectedView,
    onSelect,
}: Props) {
    const cards = [
        {
            key: 'all',
            label: 'Active requirements',
            value: activeCount,
            description: 'Open and draft requests',
            icon: ClipboardList,
            color: 'text-primary',
            background: 'bg-primary/10',
            filter: null,
        },
        {
            key: 'headcount',
            label: 'Staffing target',
            value: summary.open_headcount,
            description: 'Requested people across active requirements',
            icon: Users,
            color: 'text-foreground',
            background: 'bg-muted',
            filter: undefined,
        },
        {
            key: 'overdue',
            label: 'Overdue',
            value: summary.overdue,
            description: 'Past the required-by date',
            icon: AlertTriangle,
            color: 'text-rose-700 dark:text-rose-400',
            background: 'bg-rose-500/10',
            filter: 'overdue' as const,
        },
        {
            key: 'due_soon',
            label: 'Due in 7 days',
            value: summary.due_this_week,
            description: 'Due today through the next 7 days',
            icon: CalendarClock,
            color: 'text-amber-700 dark:text-amber-400',
            background: 'bg-amber-500/10',
            filter: 'due_soon' as const,
        },
    ];

    return (
        <section
            aria-label="Active recruitment overview"
            className="mb-6 space-y-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-1">
                <h2 className="text-sm font-semibold">Active overview</h2>
                <p className="text-xs text-muted-foreground">
                    Company-wide · open and draft requirements
                </p>
            </div>
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                {cards.map((card) => {
                    const Icon = card.icon;
                    const content = (
                        <>
                            <div className="flex items-start justify-between gap-2">
                                <span className="text-xs font-medium text-muted-foreground sm:text-sm">
                                    {card.label}
                                </span>
                                <span
                                    className={cn(
                                        'rounded-lg p-2',
                                        card.background,
                                        card.color,
                                    )}
                                >
                                    <Icon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                            </div>
                            <span
                                className={cn(
                                    'block text-3xl font-semibold tracking-tight tabular-nums sm:text-4xl',
                                    card.color,
                                )}
                            >
                                {card.value.toLocaleString()}
                            </span>
                            <span className="hidden items-center justify-between gap-2 text-xs leading-relaxed text-muted-foreground sm:flex">
                                {card.description}
                                {card.filter !== undefined && (
                                    <ArrowUpRight
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                )}
                            </span>
                        </>
                    );
                    const className = cn(
                        'flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 text-left shadow-xs sm:p-5',
                        selectedView === card.key &&
                            'border-primary/50 ring-1 ring-primary/15',
                    );

                    return card.filter === undefined ? (
                        <div key={card.key} className={className}>
                            {content}
                        </div>
                    ) : (
                        <button
                            key={card.key}
                            type="button"
                            onClick={() => onSelect(card.filter)}
                            aria-label={`View ${card.label.toLowerCase()}: ${card.value}`}
                            className={cn(
                                className,
                                'transition-colors hover:border-primary/50 hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            )}
                        >
                            {content}
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
