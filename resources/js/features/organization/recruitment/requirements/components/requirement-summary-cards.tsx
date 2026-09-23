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

type DeadlineHealthFilter = 'overdue' | 'due_soon' | null;

type Props = {
    summary: RequirementSummaryCardsData;
    activeTab?: RequirementTab;
    activeDeadlineHealth?: string | null;
    onSelectTab?: (tab: RequirementTab) => void;
    /** Called when a card should apply a deadline_health filter instead of just switching the tab */
    onSelectFilter?: (deadlineHealth: DeadlineHealthFilter) => void;
};

type SummaryCardDef = {
    key: string;
    label: string;
    sublabel: string;
    value: number;
    icon: LucideIcon;
    ariaLabel: string;
    /** If set, clicking navigates to this tab (non-filtered cards) */
    tabTarget?: RequirementTab;
    /** If set, clicking applies this deadline_health filter */
    filterTarget?: DeadlineHealthFilter;
    activeCondition?: (
        props: Pick<Props, 'activeTab' | 'activeDeadlineHealth'>,
    ) => boolean;
    cardClass: string;
    valueClass: string;
    iconClass: string;
    accentBarClass: string;
};

export function RequirementSummaryCards({
    summary,
    activeTab,
    activeDeadlineHealth,
    onSelectTab,
    onSelectFilter,
}: Props) {
    const cards: SummaryCardDef[] = [
        {
            key: 'open_headcount',
            label: 'Open Headcount',
            sublabel: 'Positions to fill across open requirements',
            value: summary.open_headcount,
            icon: Users,
            ariaLabel: `${summary.open_headcount} open headcount — click to view active requirements`,
            tabTarget: 'active',
            activeCondition: ({ activeTab: t, activeDeadlineHealth: dh }) =>
                t === 'active' && !dh,
            cardClass:
                'border-border/60 bg-card hover:border-primary/40 dark:bg-card/40',
            valueClass: 'text-foreground font-extrabold',
            iconClass: 'text-primary/70',
            accentBarClass: 'bg-primary/60',
        },
        {
            key: 'overdue',
            label: 'Overdue',
            sublabel: 'Passed required-by target date',
            value: summary.overdue,
            icon: AlertTriangle,
            ariaLabel: `${summary.overdue} overdue requirements — click to filter`,
            filterTarget: 'overdue',
            activeCondition: ({ activeDeadlineHealth: dh }) => dh === 'overdue',
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
            key: 'due_this_week',
            label: 'Due This Week',
            sublabel: 'Expiring within next 7 days',
            value: summary.due_this_week,
            icon: CalendarClock,
            ariaLabel: `${summary.due_this_week} requirements due this week — click to filter`,
            filterTarget: 'due_soon',
            activeCondition: ({ activeDeadlineHealth: dh }) =>
                dh === 'due_soon',
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
            key: 'ready_to_close',
            label: 'Ready to Close',
            sublabel: 'Headcount fulfilled, awaiting review',
            value: summary.ready_to_close,
            icon: CheckCircle2,
            ariaLabel: `${summary.ready_to_close} requirements ready to close — click to view active requirements`,
            tabTarget: 'active',
            activeCondition: () => false, // informational card — no active highlight
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

    const handleCardClick = (card: SummaryCardDef) => {
        if (card.filterTarget !== undefined && onSelectFilter) {
            onSelectFilter(card.filterTarget);
        } else if (card.tabTarget && onSelectTab) {
            onSelectTab(card.tabTarget);
        }
    };

    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {cards.map((card) => {
                const Icon = card.icon;
                const isClickable = Boolean(
                    (card.filterTarget !== undefined && onSelectFilter) ||
                    (card.tabTarget && onSelectTab),
                );
                const isActive = card.activeCondition
                    ? card.activeCondition({ activeTab, activeDeadlineHealth })
                    : false;

                return (
                    <Card
                        key={card.key}
                        role={isClickable ? 'button' : undefined}
                        tabIndex={isClickable ? 0 : undefined}
                        aria-label={card.ariaLabel}
                        aria-pressed={isClickable ? isActive : undefined}
                        onClick={() => isClickable && handleCardClick(card)}
                        onKeyDown={(e) => {
                            if (
                                isClickable &&
                                (e.key === 'Enter' || e.key === ' ')
                            ) {
                                e.preventDefault();
                                handleCardClick(card);
                            }
                        }}
                        className={cn(
                            'group relative overflow-hidden shadow-xs backdrop-blur-xs transition-all duration-200',
                            card.cardClass,
                            isClickable &&
                                'cursor-pointer hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            isActive && isClickable && 'ring-2 ring-primary/30',
                        )}
                    >
                        <div
                            className={cn(
                                'absolute top-0 right-0 left-0 h-1 transition-all',
                                card.accentBarClass,
                            )}
                            aria-hidden="true"
                        />
                        <CardContent className="p-4">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 space-y-1">
                                    <p className="text-[10px] font-semibold tracking-wider text-muted-foreground/80 uppercase">
                                        {card.label}
                                    </p>
                                    <div
                                        className={cn(
                                            'text-2xl tracking-tight tabular-nums',
                                            card.valueClass,
                                        )}
                                    >
                                        {card.value}
                                    </div>
                                    <p className="text-[10px] leading-snug text-muted-foreground/70">
                                        {card.sublabel}
                                    </p>
                                </div>
                                <div className="shrink-0 rounded-xl border border-border/40 bg-muted/30 p-2 transition-transform group-hover:scale-105">
                                    <Icon
                                        className={cn(
                                            'h-4 w-4',
                                            card.iconClass,
                                        )}
                                        aria-hidden="true"
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
