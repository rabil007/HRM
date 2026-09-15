import type { Ship } from 'lucide-react';
import {
    Anchor,
    ArrowDownRight,
    ArrowUpRight,
    CheckCircle2,
    ShieldAlert,
    UserMinus,
    UsersRound,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import type { CrewOperationsDailyPulse } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';

type Tone = 'danger' | 'warning' | 'success' | 'default';

function toneClasses(tone: Tone): {
    icon: string;
    value: string;
    surface: string;
} {
    return {
        danger: {
            icon: 'border-destructive/25 bg-destructive/10 text-destructive',
            value: 'text-destructive',
            surface: 'border-destructive/25',
        },
        warning: {
            icon: 'border-warning/25 bg-warning/10 text-warning',
            value: 'text-warning',
            surface: 'border-warning/25',
        },
        success: {
            icon: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
            value: 'text-emerald-600 dark:text-emerald-400',
            surface: 'border-emerald-500/25',
        },
        default: {
            icon: 'border-border/60 bg-muted/40 text-muted-foreground',
            value: 'text-foreground',
            surface: 'border-border/60',
        },
    }[tone];
}

function StatusCard({
    label,
    value,
    detail,
    icon: Icon,
    tone = 'default',
    children,
}: {
    label: string;
    value: string | number;
    detail: string;
    icon: typeof Ship;
    tone?: Tone;
    children?: ReactNode;
}): ReactElement {
    const classes = toneClasses(tone);

    return (
        <article
            className={cn(
                'min-w-0 rounded-xl border bg-card/80 p-4 shadow-xs dark:bg-white/2',
                classes.surface,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold text-muted-foreground">
                        {label}
                    </p>
                    <p
                        className={cn(
                            'mt-1 text-3xl font-bold tracking-tight tabular-nums',
                            classes.value,
                        )}
                    >
                        {value}
                    </p>
                </div>
                <div
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-lg border',
                        classes.icon,
                    )}
                >
                    <Icon className="size-4" />
                </div>
            </div>
            <p className="mt-2 text-xs leading-5 text-muted-foreground/75">
                {detail}
            </p>
            {children ? <div className="mt-3">{children}</div> : null}
        </article>
    );
}

function SplitBar({
    primary,
    secondary,
    primaryClassName,
    secondaryClassName,
}: {
    primary: number;
    secondary: number;
    primaryClassName: string;
    secondaryClassName: string;
}): ReactElement {
    const total = primary + secondary;

    return (
        <div className="flex h-1.5 overflow-hidden rounded-full bg-muted/50 dark:bg-white/8">
            {total > 0 ? (
                <>
                    <span
                        className={primaryClassName}
                        style={{ width: `${(primary / total) * 100}%` }}
                    />
                    <span
                        className={secondaryClassName}
                        style={{ width: `${(secondary / total) * 100}%` }}
                    />
                </>
            ) : (
                <span className="w-full bg-emerald-500/60" />
            )}
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
    const movementTotal = pulse.joins_next_7_days + pulse.signoffs_next_7_days;
    const netMovement = pulse.joins_next_7_days - pulse.signoffs_next_7_days;
    const attentionSignals =
        pulse.signoffs_overdue +
        pulse.coverage_risks.current +
        pulse.coverage_risks.upcoming;
    const attentionTone: Tone =
        pulse.signoffs_overdue > 0 || pulse.coverage_risks.current > 0
            ? 'danger'
            : pulse.coverage_risks.upcoming > 0
              ? 'warning'
              : 'success';
    const signoffTone: Tone =
        pulse.signoffs_overdue > 0
            ? 'danger'
            : pulse.signoffs_next_7_days > 0
              ? 'warning'
              : 'success';

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatusCard
                label="Crew on board"
                value={pulse.onboard_now}
                detail="Active P4 crew assigned to vessels now"
                icon={Anchor}
            >
                <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-muted-foreground">
                    <span className="size-1.5 rounded-full bg-emerald-500" />
                    Live vessel complement
                </span>
            </StatusCard>

            <StatusCard
                label="Movement balance"
                value={movementTotal}
                detail={
                    movementTotal === 0
                        ? 'No crew movements planned this week'
                        : netMovement === 0
                          ? 'Joins and sign-offs are balanced this week'
                          : `${Math.abs(netMovement)} ${netMovement > 0 ? 'more joining' : 'more signing off'} this week`
                }
                icon={UsersRound}
                tone={netMovement < 0 ? 'warning' : 'default'}
            >
                <SplitBar
                    primary={pulse.joins_next_7_days}
                    secondary={pulse.signoffs_next_7_days}
                    primaryClassName="bg-teal-500"
                    secondaryClassName="bg-amber-400 dark:bg-amber-500"
                />
                <div className="mt-2 flex items-center justify-between text-[11px] font-semibold tabular-nums">
                    <span className="flex items-center gap-1 text-teal-600 dark:text-teal-400">
                        <ArrowUpRight className="size-3" />
                        {pulse.joins_next_7_days} joins
                    </span>
                    <span className="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                        <ArrowDownRight className="size-3" />
                        {pulse.signoffs_next_7_days} off
                    </span>
                </div>
            </StatusCard>

            <StatusCard
                label="Sign-off urgency"
                value={pulse.signoffs_next_7_days}
                detail={
                    pulse.signoffs_overdue > 0
                        ? `${pulse.signoffs_overdue} sign-off${pulse.signoffs_overdue === 1 ? '' : 's'} overdue and requires follow-up`
                        : pulse.signoffs_next_7_days > 0
                          ? 'Confirm relief and travel readiness before sign-off'
                          : 'No sign-offs due in the next seven days'
                }
                icon={UserMinus}
                tone={signoffTone}
            >
                {pulse.signoffs_overdue > 0 ? (
                    <span className="inline-flex rounded-md bg-destructive/10 px-2 py-1 text-[11px] font-bold text-destructive">
                        {pulse.signoffs_overdue} overdue
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="size-3.5" />
                        No overdue sign-offs
                    </span>
                )}
            </StatusCard>

            <StatusCard
                label="Operational attention"
                value={attentionSignals === 0 ? 'Clear' : attentionSignals}
                detail={
                    attentionSignals === 0
                        ? 'No overdue movements or staffing gaps need action'
                        : canViewProjected
                          ? 'Overdue sign-offs and staffing risks that need follow-up'
                          : 'Overdue sign-offs and live staffing gaps that need follow-up'
                }
                icon={attentionSignals === 0 ? CheckCircle2 : ShieldAlert}
                tone={attentionTone}
            >
                <SplitBar
                    primary={
                        pulse.signoffs_overdue + pulse.coverage_risks.current
                    }
                    secondary={pulse.coverage_risks.upcoming}
                    primaryClassName="bg-destructive"
                    secondaryClassName="bg-warning"
                />
                <div className="mt-2 flex justify-between text-[11px] font-semibold text-muted-foreground tabular-nums">
                    <span>
                        Now:{' '}
                        {pulse.signoffs_overdue + pulse.coverage_risks.current}
                    </span>
                    <span>Upcoming: {pulse.coverage_risks.upcoming}</span>
                </div>
            </StatusCard>
        </div>
    );
}
