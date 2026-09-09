import { AlertTriangle, Info, Ship, Users, Waves } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { CrewTimelineSummary, CrewTimelineSummaryFilter } from './types';

type SummaryCard = {
    key: CrewTimelineSummaryFilter;
    label: string;
    icon: typeof Users;
    value: string;
    suffix: string | null;
    subtext: string | null;
    className: string;
    activeClassName: string;
    iconClassName: string;
    valueClassName: string;
};

export function CrewTimelineSummaryCards({
    summary,
    activeFilter,
    onSelect,
}: {
    summary: CrewTimelineSummary;
    activeFilter: CrewTimelineSummaryFilter;
    onSelect: (filter: CrewTimelineSummaryFilter) => void;
}) {
    const hasUnresolved =
        (summary.unresolved_blocking_warning_count ??
            summary.blocking_warning_count) > 0;
    const hasSkipped = (summary.skipped_employees ?? 0) > 0;

    const cards: SummaryCard[] = [
        {
            key: '',
            label: 'Employees',
            icon: Users,
            value: String(summary.total_employees),
            suffix: null,
            subtext: hasSkipped
                ? `${summary.included_employees} included · ${summary.skipped_employees} skipped`
                : null,
            className: 'border-border',
            activeClassName: 'border-primary/40 ring-1 ring-primary/25',
            iconClassName: 'bg-muted text-foreground',
            valueClassName: '',
        },
        {
            key: 'sign_on_standby',
            label: 'Sign-On Standby',
            icon: Ship,
            value: summary.total_sign_on_standby_days,
            suffix: 'days',
            subtext: null,
            className: 'border-sky-500/20',
            activeClassName: 'border-sky-500/40 ring-1 ring-sky-500/25',
            iconClassName: 'bg-sky-500/10 text-sky-600 dark:text-sky-300',
            valueClassName: '',
        },
        {
            key: 'onsite',
            label: 'Onsite',
            icon: Waves,
            value: summary.total_onsite_days,
            suffix: 'days',
            subtext: null,
            className: 'border-emerald-500/20',
            activeClassName: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
            iconClassName:
                'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
            valueClassName: '',
        },
        {
            key: 'sign_off_standby',
            label: 'Sign-Off Standby',
            icon: Ship,
            value: summary.total_sign_off_standby_days,
            suffix: 'days',
            subtext: null,
            className: 'border-indigo-500/20',
            activeClassName: 'border-indigo-500/40 ring-1 ring-indigo-500/25',
            iconClassName:
                'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300',
            valueClassName: '',
        },
        {
            key: 'blocking',
            label: 'Unresolved blockers',
            icon: AlertTriangle,
            value: String(
                summary.unresolved_blocking_warning_count ??
                    summary.blocking_warning_count,
            ),
            suffix: null,
            subtext:
                summary.blocking_warning_count >
                (summary.unresolved_blocking_warning_count ??
                    summary.blocking_warning_count)
                    ? `(${summary.blocking_warning_count} total, ${summary.blocking_warning_count - (summary.unresolved_blocking_warning_count ?? 0)} skipped)`
                    : null,
            className: hasUnresolved
                ? 'border-red-500/25 bg-red-500/[0.04]'
                : 'border-border',
            activeClassName: 'border-red-500/50 ring-1 ring-red-500/30',
            iconClassName: hasUnresolved
                ? 'bg-red-500/10 text-red-600 dark:text-red-300'
                : 'bg-muted text-muted-foreground',
            valueClassName: hasUnresolved
                ? 'text-red-600 dark:text-red-300'
                : '',
        },
        {
            key: 'informational',
            label: 'Informational',
            icon: Info,
            value: String(summary.informational_warning_count),
            suffix: null,
            subtext: null,
            className: 'border-amber-500/25 bg-amber-500/[0.04]',
            activeClassName: 'border-amber-500/50 ring-1 ring-amber-500/30',
            iconClassName: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
            valueClassName: '',
        },
    ];

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            {cards.map((card) => {
                const Icon = card.icon;
                const isActive = card.key === activeFilter;

                return (
                    <button
                        key={card.key || 'employees'}
                        type="button"
                        aria-pressed={isActive}
                        onClick={() => onSelect(isActive ? '' : card.key)}
                        className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'h-full cursor-pointer glass-card transition-shadow hover:shadow-md',
                                card.className,
                                isActive && card.activeClassName,
                            )}
                        >
                            <CardContent className="flex items-center gap-3 p-4">
                                <span
                                    className={cn(
                                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                        card.iconClassName,
                                    )}
                                >
                                    <Icon className="h-5 w-5" />
                                </span>
                                <div className="min-w-0 space-y-0.5">
                                    <p className="truncate text-xs text-muted-foreground">
                                        {card.label}
                                    </p>
                                    <p
                                        className={cn(
                                            'flex items-baseline gap-1 text-2xl font-bold tabular-nums',
                                            card.valueClassName,
                                        )}
                                    >
                                        {card.value}
                                        {card.suffix ? (
                                            <span className="text-xs font-normal text-muted-foreground">
                                                {card.suffix}
                                            </span>
                                        ) : null}
                                    </p>
                                    {card.subtext ? (
                                        <p className="truncate text-[10px] font-medium text-muted-foreground">
                                            {card.subtext}
                                        </p>
                                    ) : null}
                                </div>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}
        </div>
    );
}
