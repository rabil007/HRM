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
        className: 'border-border',
    },
    {
        key: 'sign_on',
        label: 'Sign-On Standby days',
        icon: Ship,
        value: (s: CrewTimelineSummary) => s.total_sign_on_standby_days,
        className: 'border-sky-500/20',
    },
    {
        key: 'onsite',
        label: 'Onsite days',
        icon: Waves,
        value: (s: CrewTimelineSummary) => s.total_onsite_days,
        className: 'border-emerald-500/20',
    },
    {
        key: 'sign_off',
        label: 'Sign-Off Standby days',
        icon: Ship,
        value: (s: CrewTimelineSummary) => s.total_sign_off_standby_days,
        className: 'border-indigo-500/20',
    },
    {
        key: 'blocking',
        label: 'Blocking warnings',
        icon: AlertTriangle,
        value: (s: CrewTimelineSummary) => String(s.blocking_warning_count),
        className: 'border-red-500/25 bg-red-500/[0.04]',
    },
    {
        key: 'info',
        label: 'Informational warnings',
        icon: Info,
        value: (s: CrewTimelineSummary) =>
            String(s.informational_warning_count),
        className: 'border-amber-500/25 bg-amber-500/[0.04]',
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

                return (
                    <Card
                        key={card.key}
                        className={cn('glass-card', card.className)}
                    >
                        <CardContent className="flex items-start gap-3 p-4">
                            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 space-y-1">
                                <p className="text-xs text-muted-foreground">
                                    {card.label}
                                </p>
                                <p className="text-lg font-semibold tabular-nums">
                                    {card.value(summary)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
