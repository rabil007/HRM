import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ShieldAlert,
    UserRoundCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactElement } from 'react';
import type {
    CrewOperationsActionItem,
    CrewOperationsManningReliefRisk,
    CrewOperationsNextDay,
    CrewOperationsProjectedManning,
} from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

type BriefingTone = 'critical' | 'warning' | 'healthy' | 'neutral';

function BriefingItem({
    label,
    value,
    detail,
    icon: Icon,
    tone,
}: {
    label: string;
    value: string | number;
    detail: string;
    icon: LucideIcon;
    tone: BriefingTone;
}): ReactElement {
    return (
        <div className="flex min-w-0 gap-3 px-4 py-3.5">
            <div
                className={cn(
                    'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                    tone === 'critical' && 'bg-destructive/10 text-destructive',
                    tone === 'warning' && 'bg-warning/10 text-warning',
                    tone === 'healthy' &&
                        'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    tone === 'neutral' && 'bg-muted/50 text-muted-foreground',
                )}
            >
                <Icon className="size-4" />
            </div>
            <div className="min-w-0">
                <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p
                    className={cn(
                        'mt-0.5 truncate text-base font-bold tabular-nums',
                        tone === 'critical' && 'text-destructive',
                        tone === 'warning' && 'text-warning',
                        tone === 'healthy' &&
                            'text-emerald-600 dark:text-emerald-400',
                    )}
                >
                    {value}
                </p>
                <p className="mt-0.5 line-clamp-2 text-[11px] leading-4 text-muted-foreground/75">
                    {detail}
                </p>
            </div>
        </div>
    );
}

export function OperationalBriefing({
    actionItems,
    movementDays,
    reliefRisks,
    projectedManning,
}: {
    actionItems: CrewOperationsActionItem[];
    movementDays: CrewOperationsNextDay[];
    reliefRisks: CrewOperationsManningReliefRisk[];
    projectedManning: CrewOperationsProjectedManning | null;
}): ReactElement {
    const criticalActions = actionItems.filter(
        (item) => item.severity === 'critical',
    ).length;
    const warningActions = actionItems.length - criticalActions;
    const busiestDay = movementDays.reduce<CrewOperationsNextDay | null>(
        (busiest, day) => {
            if (!busiest) {
                return day;
            }

            const movementCount = day.joins + day.signoffs;
            const busiestCount = busiest.joins + busiest.signoffs;

            return movementCount > busiestCount ? day : busiest;
        },
        null,
    );
    const busiestDayMovements = busiestDay
        ? busiestDay.joins + busiestDay.signoffs
        : 0;
    const criticalReliefRisks = reliefRisks.filter(
        (item) => item.kind === 'relief' && item.risk === 'Critical relief',
    ).length;
    const reliefRiskCount = reliefRisks.filter(
        (item) => item.kind === 'relief',
    ).length;
    const totalCoverageGaps = projectedManning
        ? projectedManning.current_gap_positions +
          projectedManning.future_gap_positions
        : 0;

    return (
        <aside className="overflow-hidden rounded-xl border border-border/60 bg-muted/10 dark:border-white/5 dark:bg-white/1">
            <div className="flex flex-col gap-1 border-b border-border/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/5">
                <div>
                    <h3 className="text-sm font-semibold text-foreground">
                        Operational briefing
                    </h3>
                    <p className="text-[11px] text-muted-foreground">
                        Live signals calculated from the current crew plan
                    </p>
                </div>
                <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-primary/8 px-2 py-1 text-[10px] font-semibold text-primary">
                    <span className="size-1.5 animate-pulse rounded-full bg-primary" />
                    Refreshes every minute
                </span>
            </div>

            <div className="grid divide-y divide-border/50 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4 dark:divide-white/5">
                <BriefingItem
                    label="Priority load"
                    value={
                        criticalActions > 0
                            ? `${criticalActions} critical`
                            : actionItems.length > 0
                              ? `${warningActions} to review`
                              : 'Clear'
                    }
                    detail={
                        actionItems.length === 0
                            ? 'No operational exceptions are waiting'
                            : `${actionItems.length} total item${actionItems.length === 1 ? '' : 's'} in the action queue`
                    }
                    icon={criticalActions > 0 ? AlertTriangle : CheckCircle2}
                    tone={
                        criticalActions > 0
                            ? 'critical'
                            : warningActions > 0
                              ? 'warning'
                              : 'healthy'
                    }
                />

                <BriefingItem
                    label="Peak movement day"
                    value={
                        busiestDayMovements > 0 && busiestDay
                            ? busiestDay.label
                            : 'No movements'
                    }
                    detail={
                        busiestDayMovements > 0 && busiestDay
                            ? `${formatDisplayDate(busiestDay.date)} · ${busiestDay.joins} join${busiestDay.joins === 1 ? '' : 's'} · ${busiestDay.signoffs} sign-off${busiestDay.signoffs === 1 ? '' : 's'}`
                            : 'No crew-change workload in the next seven days'
                    }
                    icon={CalendarClock}
                    tone={busiestDayMovements > 0 ? 'neutral' : 'healthy'}
                />

                <BriefingItem
                    label="Relief exposure"
                    value={reliefRiskCount > 0 ? reliefRiskCount : 'Clear'}
                    detail={
                        reliefRiskCount === 0
                            ? 'No relief readiness risks identified'
                            : criticalReliefRisks > 0
                              ? `${criticalReliefRisks} critical relief case${criticalReliefRisks === 1 ? '' : 's'} need immediate planning`
                              : 'Relief cases need planning follow-up'
                    }
                    icon={UserRoundCheck}
                    tone={
                        criticalReliefRisks > 0
                            ? 'critical'
                            : reliefRiskCount > 0
                              ? 'warning'
                              : 'healthy'
                    }
                />

                <BriefingItem
                    label="Coverage exposure"
                    value={
                        projectedManning
                            ? totalCoverageGaps > 0
                                ? `${totalCoverageGaps} gaps`
                                : 'Protected'
                            : 'Unavailable'
                    }
                    detail={
                        !projectedManning
                            ? 'Projected manning data is not available'
                            : totalCoverageGaps === 0
                              ? `No gaps across the ${projectedManning.horizon_days}-day horizon`
                              : `${projectedManning.projected_shortfall_days} shortfall day${projectedManning.projected_shortfall_days === 1 ? '' : 's'} · next ${projectedManning.next_gap_date ? formatDisplayDate(projectedManning.next_gap_date) : 'date pending'}`
                    }
                    icon={totalCoverageGaps > 0 ? ShieldAlert : CheckCircle2}
                    tone={
                        !projectedManning
                            ? 'neutral'
                            : projectedManning.current_gap_positions > 0
                              ? 'critical'
                              : projectedManning.future_gap_positions > 0
                                ? 'warning'
                                : 'healthy'
                    }
                />
            </div>
        </aside>
    );
}
