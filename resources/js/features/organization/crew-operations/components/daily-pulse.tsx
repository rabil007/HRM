import type { Ship } from 'lucide-react';
import { AlertTriangle, Anchor, UserMinus, UserPlus } from 'lucide-react';
import type { ReactElement } from 'react';
import type { CrewOperationsDailyPulse } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';

function PulseMetric({
    label,
    value,
    hint,
    icon: Icon,
    tone,
}: {
    label: string;
    value: string | number;
    hint?: string;
    icon: typeof Ship;
    tone?: 'danger' | 'warning' | 'default';
}): ReactElement {
    return (
        <div className="group relative overflow-hidden rounded-2xl border glass-card border-border/60 bg-card/80 p-5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:shadow-md dark:hover:border-white/10">
            <div className="relative flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        {label}
                    </p>
                    <p
                        className={cn(
                            'text-3xl font-extrabold tracking-tight tabular-nums',
                            tone === 'danger' && 'text-destructive',
                            tone === 'warning' && 'text-warning',
                        )}
                    >
                        {value}
                    </p>
                    {hint ? (
                        <p className="text-xs font-medium text-muted-foreground/75">
                            {hint}
                        </p>
                    ) : null}
                </div>
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/6">
                    <Icon className="size-5 text-muted-foreground" />
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
    const coverageHint = canViewProjected
        ? `${pulse.coverage_risks.current} now · ${pulse.coverage_risks.upcoming} upcoming`
        : `${pulse.coverage_risks.current} now`;

    const coverageTone =
        pulse.coverage_risks.current > 0
            ? 'danger'
            : pulse.coverage_risks.upcoming > 0
              ? 'warning'
              : 'default';

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <PulseMetric
                label="Onboard Now"
                value={pulse.onboard_now}
                hint="Actual P4 crew on vessel"
                icon={Anchor}
            />
            <PulseMetric
                label="Joins — Next 7 Days"
                value={pulse.joins_next_7_days}
                hint="Planned joins this week"
                icon={UserPlus}
            />
            <PulseMetric
                label="Sign-offs — Next 7 Days"
                value={pulse.signoffs_next_7_days}
                hint={
                    pulse.signoffs_overdue > 0
                        ? `${pulse.signoffs_overdue} overdue`
                        : 'Planned sign-offs this week'
                }
                icon={UserMinus}
                tone={pulse.signoffs_overdue > 0 ? 'danger' : 'default'}
            />
            <PulseMetric
                label="Coverage Risks"
                value={coverageHint}
                hint={
                    canViewProjected
                        ? 'Actual gaps now · projected future gaps'
                        : 'Actual manning gaps now'
                }
                icon={AlertTriangle}
                tone={coverageTone}
            />
        </div>
    );
}
