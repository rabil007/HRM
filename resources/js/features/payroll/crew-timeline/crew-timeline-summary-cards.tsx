import { AlertTriangle, Info, Ship, Users, Waves } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { CrewTimelineSummary } from './types';

const CARDS = [
    {
        key: 'total_employees',
        label: 'Employees',
        icon: Users,
        value: (s: CrewTimelineSummary) => String(s.total_employees),
        suffix: null,
        className: 'border-border',
        iconClassName: 'bg-muted text-foreground',
        valueClassName: '',
    },
    {
        key: 'sign_on',
        label: 'Sign-On Standby',
        icon: Ship,
        value: (s: CrewTimelineSummary) => s.total_sign_on_standby_days,
        suffix: 'days',
        className: 'border-sky-500/20',
        iconClassName: 'bg-sky-500/10 text-sky-600 dark:text-sky-300',
        valueClassName: '',
    },
    {
        key: 'onsite',
        label: 'Onsite',
        icon: Waves,
        value: (s: CrewTimelineSummary) => s.total_onsite_days,
        suffix: 'days',
        className: 'border-emerald-500/20',
        iconClassName:
            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
        valueClassName: '',
    },
    {
        key: 'sign_off',
        label: 'Sign-Off Standby',
        icon: Ship,
        value: (s: CrewTimelineSummary) => s.total_sign_off_standby_days,
        suffix: 'days',
        className: 'border-indigo-500/20',
        iconClassName: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300',
        valueClassName: '',
    },
    {
        key: 'blocking',
        label: 'Blocking warnings',
        icon: AlertTriangle,
        value: (s: CrewTimelineSummary) => String(s.blocking_warning_count),
        suffix: null,
        className: 'border-red-500/25 bg-red-500/[0.04]',
        iconClassName: 'bg-red-500/10 text-red-600 dark:text-red-300',
        valueClassName: '',
    },
    {
        key: 'info',
        label: 'Informational',
        icon: Info,
        value: (s: CrewTimelineSummary) =>
            String(s.informational_warning_count),
        suffix: null,
        className: 'border-amber-500/25 bg-amber-500/[0.04]',
        iconClassName: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
        valueClassName: '',
    },
] as const;

export function CrewTimelineSummaryCards({
    summary,
}: {
    summary: CrewTimelineSummary;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            {CARDS.map((card) => {
                const Icon = card.icon;
                const value = card.value(summary);

                return (
                    <Card
                        key={card.key}
                        className={cn(
                            'glass-card transition-shadow hover:shadow-md',
                            card.className,
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
                                <p className="flex items-baseline gap-1 text-2xl font-bold tabular-nums">
                                    {value}
                                    {'suffix' in card && card.suffix ? (
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {card.suffix}
                                        </span>
                                    ) : null}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
