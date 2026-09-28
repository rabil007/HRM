import { AlertCircle, AlertTriangle, Calendar, UserX } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { CrewReliefFilters, CrewReliefSummary } from './types';

interface SummaryCardItem {
    key: keyof CrewReliefSummary;
    preset: string;
    label: string;
    icon: typeof AlertCircle;
    valueColor: string;
    iconColor: string;
    activeBorder: string;
}

const CARDS: SummaryCardItem[] = [
    {
        key: 'signing_off_next_7_days',
        preset: 'next_7_days',
        label: 'Signing Off Next 7 Days',
        icon: Calendar,
        valueColor: 'text-amber-600 dark:text-amber-400',
        iconColor: 'text-amber-500/70',
        activeBorder: 'border-amber-500 ring-1 ring-amber-500/30',
    },
    {
        key: 'no_relief_assigned',
        preset: 'no_relief',
        label: 'No Relief Assigned',
        icon: UserX,
        valueColor: 'text-rose-600 dark:text-rose-400',
        iconColor: 'text-rose-500/70',
        activeBorder: 'border-rose-500 ring-1 ring-rose-500/30',
    },
    {
        key: 'relief_not_ready',
        preset: 'not_ready',
        label: 'Relief Not Ready',
        icon: AlertTriangle,
        valueColor: 'text-orange-600 dark:text-orange-400',
        iconColor: 'text-orange-500/70',
        activeBorder: 'border-orange-500 ring-1 ring-orange-500/30',
    },
    {
        key: 'overdue_signoffs',
        preset: 'overdue',
        label: 'Overdue Sign-Offs',
        icon: AlertCircle,
        valueColor: 'text-destructive',
        iconColor: 'text-destructive/70',
        activeBorder: 'border-destructive ring-1 ring-destructive/30',
    },
];

export function CrewReliefSummaryCards({
    summary,
    filters,
    onSelectPreset,
}: {
    summary: CrewReliefSummary;
    filters: CrewReliefFilters;
    onSelectPreset: (preset: string) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {CARDS.map((card) => {
                const isActive = filters.preset === card.preset;
                const count = summary[card.key] ?? 0;
                const Icon = card.icon;

                return (
                    <Card
                        key={card.key}
                        className={cn(
                            'cursor-pointer transition-all hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            isActive
                                ? card.activeBorder
                                : 'border-border/60 hover:border-border',
                        )}
                        onClick={() => {
                            if (isActive) {
                                onSelectPreset('next_30_days');
                            } else {
                                onSelectPreset(card.preset);
                            }
                        }}
                        role="button"
                        tabIndex={0}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();

                                if (isActive) {
                                    onSelectPreset('next_30_days');
                                } else {
                                    onSelectPreset(card.preset);
                                }
                            }
                        }}
                        aria-pressed={isActive}
                    >
                        <CardContent className="p-3.5 sm:p-4">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-medium text-muted-foreground sm:text-sm">
                                    {card.label}
                                </span>
                                <Icon
                                    className={cn(
                                        'h-4 w-4 shrink-0',
                                        card.iconColor,
                                    )}
                                />
                            </div>
                            <div
                                className={cn(
                                    'mt-2 text-2xl font-bold sm:text-3xl',
                                    card.valueColor,
                                )}
                            >
                                {count}
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
