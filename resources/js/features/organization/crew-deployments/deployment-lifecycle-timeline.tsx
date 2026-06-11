import { ArrowRight, PlaneLanding, PlaneTakeoff, Ship } from 'lucide-react';
import type { ReactElement } from 'react';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';
import { cn } from '@/lib/utils';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';

type PhaseState = 'complete' | 'current' | 'pending' | 'warning';

type Phase = {
    id: string;
    label: string;
    icon: ReactElement;
    state: PhaseState;
    primary?: string;
    secondary?: string;
    meta?: string;
};

function displayDate(value: string | null | undefined): string {
    return formatIsoDateDisplay(value);
}

function displayDays(value: number | null | undefined): string | undefined {
    if (value === null || value === undefined) {
        return undefined;
    }

    return `${value}d`;
}

function phaseState(
    hasData: boolean,
    isCurrent: boolean,
    isWarning: boolean,
): PhaseState {
    if (isWarning) {
        return 'warning';
    }

    if (isCurrent) {
        return 'current';
    }

    if (hasData) {
        return 'complete';
    }

    return 'pending';
}

const STATE_STYLES: Record<PhaseState, string> = {
    complete: 'border-border/80 bg-muted/20 dark:border-white/10 dark:bg-white/[0.03]',
    current: 'border-primary/40 bg-primary/10 shadow-sm shadow-primary/10',
    pending: 'border-dashed border-border/50 bg-transparent opacity-55 dark:border-white/10',
    warning: 'border-red-500/30 bg-red-500/10',
};

const STATE_DOT: Record<PhaseState, string> = {
    complete: 'bg-emerald-500',
    current: 'bg-primary animate-pulse',
    pending: 'bg-muted-foreground/30',
    warning: 'bg-red-500',
};

function buildPhases(deployment: DeploymentItem): Phase[] {
    const status = deployment.status;

    const hasArrived = Boolean(deployment.arrived_date);
    const hasJoinStandby = Boolean(deployment.join_standby_from);
    const hasVesselTour = Boolean(deployment.joined_date);
    const hasLeaveStandby = Boolean(deployment.leave_standby_from);
    const hasTravel = Boolean(deployment.travelled_date);

    const joinStandbyCurrent = status === 'join_standby';
    const onVesselCurrent = status === 'on_vessel';
    const leaveStandbyCurrent = status === 'leave_standby';
    const travelCurrent = status === 'travel';
    const awaitingCurrent = status === 'awaiting_join';
    const disembarkedCurrent = status === 'disembarked';
    const needsUpdate = status === 'unknown';

    return [
        {
            id: 'arrived',
            label: 'Arrived',
            icon: <PlaneLanding className="h-4 w-4" />,
            state: phaseState(
                hasArrived,
                awaitingCurrent,
                needsUpdate && hasArrived && !hasJoinStandby && !hasVesselTour,
            ),
            primary: displayDate(deployment.arrived_date),
            meta: deployment.hire_date
                ? `Hired ${displayDate(deployment.hire_date)}`
                : undefined,
        },
        {
            id: 'join_standby',
            label: 'Join standby',
            icon: <PlaneTakeoff className="h-4 w-4" />,
            state: phaseState(
                hasJoinStandby,
                joinStandbyCurrent,
                needsUpdate &&
                    hasJoinStandby &&
                    Boolean(deployment.join_standby_to) &&
                    !hasVesselTour,
            ),
            primary: hasJoinStandby
                ? `${displayDate(deployment.join_standby_from)}${deployment.join_standby_to ? ` → ${displayDate(deployment.join_standby_to)}` : ' → open'}`
                : '—',
            meta: displayDays(deployment.join_standby_days),
        },
        {
            id: 'on_vessel',
            label: 'On vessel',
            icon: <Ship className="h-4 w-4" />,
            state: phaseState(
                hasVesselTour,
                onVesselCurrent || disembarkedCurrent,
                needsUpdate && hasVesselTour && Boolean(deployment.disembarked_date) && !hasLeaveStandby && !hasTravel,
            ),
            primary: hasVesselTour
                ? `${displayDate(deployment.joined_date)} → ${displayDate(deployment.disembarked_date)}`
                : '—',
            secondary: deployment.vessel_name ?? undefined,
            meta: displayDays(deployment.vessel_days),
        },
        {
            id: 'leave_standby',
            label: 'Leave standby',
            icon: <PlaneTakeoff className="h-4 w-4 rotate-180" />,
            state: phaseState(
                hasLeaveStandby,
                leaveStandbyCurrent,
                needsUpdate &&
                    hasLeaveStandby &&
                    Boolean(deployment.leave_standby_to) &&
                    !hasTravel,
            ),
            primary: hasLeaveStandby
                ? `${displayDate(deployment.leave_standby_from)}${deployment.leave_standby_to ? ` → ${displayDate(deployment.leave_standby_to)}` : ' → open'}`
                : '—',
            meta: displayDays(deployment.leave_standby_days),
        },
        {
            id: 'travel',
            label: 'Travelled',
            icon: <PlaneTakeoff className="h-4 w-4" />,
            state: phaseState(hasTravel, travelCurrent, false),
            primary: displayDate(deployment.travelled_date),
        },
    ];
}

function PhaseCard({ phase }: { phase: Phase }): ReactElement {
    return (
        <div
            className={cn(
                'flex min-w-[168px] flex-1 flex-col gap-2 rounded-xl border p-4 transition-colors',
                STATE_STYLES[phase.state],
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    <span className="text-primary">{phase.icon}</span>
                    {phase.label}
                </div>
                <span className={cn('h-2 w-2 shrink-0 rounded-full', STATE_DOT[phase.state])} />
            </div>
            <div className="text-sm font-semibold leading-snug text-foreground">{phase.primary}</div>
            {phase.secondary ? (
                <div className="truncate text-xs text-muted-foreground">{phase.secondary}</div>
            ) : null}
            {phase.meta ? (
                <div className="text-[11px] font-medium text-muted-foreground/80">{phase.meta}</div>
            ) : null}
        </div>
    );
}

export function DeploymentLifecycleTimeline({
    deployment,
}: {
    deployment: DeploymentItem;
}): ReactElement {
    const phases = buildPhases(deployment);

    return (
        <div className="overflow-x-auto pb-1">
            <div className="flex min-w-max items-stretch gap-2 lg:min-w-0 lg:gap-3">
                {phases.map((phase, index) => (
                    <div key={phase.id} className="flex items-stretch gap-2 lg:gap-3">
                        <PhaseCard phase={phase} />
                        {index < phases.length - 1 ? (
                            <div className="flex items-center text-muted-foreground/30">
                                <ArrowRight className="hidden h-4 w-4 lg:block" />
                            </div>
                        ) : null}
                    </div>
                ))}
            </div>
        </div>
    );
}
