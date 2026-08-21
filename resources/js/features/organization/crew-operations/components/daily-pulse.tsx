import type { Ship } from 'lucide-react';
import {
    Anchor,
    ShieldAlert,
    TrendingUp,
    UserMinus,
    UserPlus,
} from 'lucide-react';
import type { ReactElement } from 'react';
import type { CrewOperationsDailyPulse } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';

function PulseMetric({
    label,
    value,
    hint,
    icon: Icon,
    tone,
    badge,
}: {
    label: string;
    value: string | number;
    hint?: string;
    icon: typeof Ship;
    tone?: 'danger' | 'warning' | 'success' | 'default';
    badge?: { text: string; tone: 'danger' | 'warning' | 'success' };
}): ReactElement {
    const accentBg = {
        danger: 'bg-destructive/8 dark:bg-destructive/12',
        warning: 'bg-warning/8 dark:bg-warning/12',
        success: 'bg-emerald-500/8 dark:bg-emerald-500/12',
        default: 'dark:bg-white/6 bg-muted/40',
    }[tone ?? 'default'];

    const iconColor = {
        danger: 'text-destructive',
        warning: 'text-warning',
        success: 'text-emerald-500',
        default: 'text-muted-foreground',
    }[tone ?? 'default'];

    const borderAccent = {
        danger: 'border-t-2 border-t-destructive/60',
        warning: 'border-t-2 border-t-warning/60',
        success: 'border-t-2 border-t-emerald-500/60',
        default: '',
    }[tone ?? 'default'];

    return (
        <div
            className={cn(
                'group relative overflow-hidden rounded-2xl border glass-card border-border/60 bg-card/80 p-5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:shadow-md dark:hover:border-white/10',
                borderAccent,
            )}
        >
            <div className="relative flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        {label}
                    </p>
                    <div className="flex items-baseline gap-2">
                        <p
                            className={cn(
                                'text-3xl font-extrabold tracking-tight tabular-nums',
                                tone === 'danger' && 'text-destructive',
                                tone === 'warning' && 'text-warning',
                                tone === 'success' && 'text-emerald-500',
                            )}
                        >
                            {value}
                        </p>
                        {badge ? (
                            <span
                                className={cn(
                                    'inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                                    badge.tone === 'danger' &&
                                        'bg-destructive/15 text-destructive',
                                    badge.tone === 'warning' &&
                                        'bg-warning/15 text-warning',
                                    badge.tone === 'success' &&
                                        'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                                )}
                            >
                                {badge.text}
                            </span>
                        ) : null}
                    </div>
                    {hint ? (
                        <p className="text-xs font-medium text-muted-foreground/65">
                            {hint}
                        </p>
                    ) : null}
                </div>
                <div
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-xl border border-border/60 dark:border-white/8',
                        accentBg,
                    )}
                >
                    <Icon className={cn('size-5', iconColor)} />
                </div>
            </div>
        </div>
    );
}

export function DailyPulse({
    pulse,
    canViewProjected,
}: {
    pulse: CrewOperationsDailyPulse;
    canViewProjected: boolean;
}): ReactElement {
    const coverageTone =
        pulse.coverage_risks.current > 0
            ? 'danger'
            : pulse.coverage_risks.upcoming > 0
              ? 'warning'
              : 'success';

    const coverageValue =
        pulse.coverage_risks.current > 0 || pulse.coverage_risks.upcoming > 0
            ? pulse.coverage_risks.current + pulse.coverage_risks.upcoming
            : 0;

    const coverageHint =
        coverageValue === 0
            ? 'All positions covered'
            : canViewProjected
              ? `${pulse.coverage_risks.current} actual · ${pulse.coverage_risks.upcoming} projected`
              : `${pulse.coverage_risks.current} understaffed now`;

    const signoffTone =
        pulse.signoffs_overdue > 0
            ? 'danger'
            : pulse.signoffs_next_7_days > 0
              ? 'warning'
              : 'default';

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <PulseMetric
                label="Onboard Now"
                value={pulse.onboard_now}
                hint="Active P4 crew currently on vessel"
                icon={Anchor}
                tone="default"
            />
            <PulseMetric
                label="Joins — Next 7 Days"
                value={pulse.joins_next_7_days}
                hint="Planned embarkations this week"
                icon={UserPlus}
                tone={pulse.joins_next_7_days > 0 ? 'success' : 'default'}
            />
            <PulseMetric
                label="Sign-offs — Next 7 Days"
                value={pulse.signoffs_next_7_days}
                hint="Planned disembarkations this week"
                icon={UserMinus}
                tone={signoffTone}
                badge={
                    pulse.signoffs_overdue > 0
                        ? {
                              text: `${pulse.signoffs_overdue} overdue`,
                              tone: 'danger',
                          }
                        : undefined
                }
            />
            <PulseMetric
                label="Coverage Risks"
                value={coverageValue === 0 ? 'Clear' : coverageValue}
                hint={coverageHint}
                icon={coverageValue === 0 ? TrendingUp : ShieldAlert}
                tone={coverageTone}
            />
        </div>
    );
}
