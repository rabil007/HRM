import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    RequirementSummaryCardsData,
    RequirementTab,
} from '@/types/recruitment';

type Props = {
    summary: RequirementSummaryCardsData;
    activeTab?: RequirementTab;
    onSelectTab?: (tab: RequirementTab) => void;
};

type SummaryCardDef = {
    label: string;
    sublabel: string;
    value: number;
    icon: LucideIcon;
    tabTarget?: RequirementTab;
    cardClass: string;
    valueClass: string;
    iconClass: string;
    accentBarClass: string;
};

export function RequirementSummaryCards({
    summary,
    activeTab,
    onSelectTab,
}: Props) {
    const cards: SummaryCardDef[] = [
        {
            label: 'Open Headcount',
            sublabel: 'Positions to fill across open requirements',
            value: summary.open_headcount,
            icon: Users,
            tabTarget: 'active',
            cardClass:
                'border-border/60 bg-card hover:border-primary/40 dark:bg-card/40',
            valueClass: 'text-foreground font-extrabold',
            iconClass: 'text-primary/70',
            accentBarClass: 'bg-primary/60',
        },
        {
            label: 'Overdue Requirements',
            sublabel: 'Passed required-by target date',
            value: summary.overdue,
            icon: AlertTriangle,
            tabTarget: 'active',
            cardClass:
                summary.overdue > 0
                    ? 'border-rose-500/25 bg-rose-500/[0.04] hover:border-rose-500/50'
                    : 'border-border/60 bg-card hover:border-border dark:bg-card/40',
            valueClass:
                summary.overdue > 0
                    ? 'text-rose-500 font-extrabold'
                    : 'text-foreground font-extrabold',
            iconClass:
                summary.overdue > 0
                    ? 'text-rose-500/80'
                    : 'text-muted-foreground/60',
            accentBarClass: summary.overdue > 0 ? 'bg-rose-500' : 'bg-muted/40',
        },
        {
            label: 'Due This Week',
            sublabel: 'Expiring within next 7 days',
            value: summary.due_this_week,
            icon: CalendarClock,
            tabTarget: 'active',
            cardClass:
                summary.due_this_week > 0
                    ? 'border-amber-500/25 bg-amber-500/[0.04] hover:border-amber-500/50'
                    : 'border-border/60 bg-card hover:border-border dark:bg-card/40',
            valueClass:
                summary.due_this_week > 0
                    ? 'text-amber-500 font-extrabold'
                    : 'text-foreground font-extrabold',
            iconClass:
                summary.due_this_week > 0
                    ? 'text-amber-500/80'
                    : 'text-muted-foreground/60',
            accentBarClass:
                summary.due_this_week > 0 ? 'bg-amber-500' : 'bg-muted/40',
        },
        {
            label: 'Ready to Close',
            sublabel: 'Headcount fulfilled, awaiting review',
            value: summary.ready_to_close,
            icon: CheckCircle2,
            tabTarget: 'active',
            cardClass:
                summary.ready_to_close > 0
                    ? 'border-emerald-500/25 bg-emerald-500/[0.04] hover:border-emerald-500/50'
                    : 'border-border/60 bg-card hover:border-border dark:bg-card/40',
            valueClass:
                summary.ready_to_close > 0
                    ? 'text-emerald-500 font-extrabold'
                    : 'text-foreground font-extrabold',
            iconClass:
                summary.ready_to_close > 0
                    ? 'text-emerald-500/80'
                    : 'text-muted-foreground/60',
            accentBarClass:
                summary.ready_to_close > 0 ? 'bg-emerald-500' : 'bg-muted/40',
        },
    ];

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {cards.map((card) => {
                const Icon = card.icon;
                const isClickable = Boolean(card.tabTarget && onSelectTab);
                const isActive = activeTab === card.tabTarget;

                return (
                    <Card
                        key={card.label}
                        onClick={() => {
                            if (card.tabTarget && onSelectTab) {
                                onSelectTab(card.tabTarget);
                            }
                        }}
                        className={cn(
                            'group relative overflow-hidden shadow-xs backdrop-blur-xs transition-all duration-200',
                            card.cardClass,
                            isClickable &&
                                'cursor-pointer hover:-translate-y-0.5 hover:shadow-md',
                            isActive && isClickable && 'ring-2 ring-primary/20',
                        )}
                    >
                        <div
                            className={cn(
                                'absolute top-0 right-0 left-0 h-1 transition-all',
                                card.accentBarClass,
                            )}
                        />
                        <CardContent className="p-5">
                            <div className="flex items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <p className="text-xs font-semibold tracking-wider text-muted-foreground/80 uppercase">
                                        {card.label}
                                    </p>
                                    <div
                                        className={cn(
                                            'text-3xl tracking-tight',
                                            card.valueClass,
                                        )}
                                    >
                                        {card.value}
                                    </div>
                                    <p className="text-[11px] leading-snug text-muted-foreground/70">
                                        {card.sublabel}
                                    </p>
                                </div>
                                <div className="rounded-xl border border-border/40 bg-muted/30 p-2.5 transition-transform group-hover:scale-105">
                                    <Icon
                                        className={cn(
                                            'h-5 w-5',
                                            card.iconClass,
                                        )}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
