import {
    AlertCircle,
    AlertTriangle,
    Anchor,
    CheckCircle2,
    Clock,
    Compass,
    GraduationCap,
    Home,
    Plane,
    Ship,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type {
    ActiveOnVesselAssignment,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export interface CrewEmployeeOperationalStatusProps {
    status: EmployeeOperationalStatus | null | undefined;
    activeOnVessel?: ActiveOnVesselAssignment | null;
    className?: string;
}

export function getEmployeeStatusContainerClass(
    statusCode: string | null | undefined,
): string {
    switch (statusCode) {
        case 'on_vessel':
            return 'border-amber-500/50 bg-amber-500/10 dark:border-amber-500/40 dark:bg-amber-500/10';
        case 'join_standby':
            return 'border-sky-500/40 bg-sky-500/5 dark:border-sky-500/30 dark:bg-sky-500/10';
        case 'demob_standby':
            return 'border-indigo-500/40 bg-indigo-500/5 dark:border-indigo-500/30 dark:bg-indigo-500/10';
        case 'in_home':
            return 'border-emerald-500/30 bg-emerald-500/5 dark:border-emerald-500/20 dark:bg-emerald-500/10';
        case 'pre_mobilisation':
        case 'travel_in':
        case 'training':
        case 'ready_to_join':
        case 'home_redeploy':
            return 'border-blue-500/30 bg-blue-500/5 dark:border-blue-500/20 dark:bg-blue-500/10';
        case 'movement_update_required':
            return 'border-rose-500/40 bg-rose-500/5 dark:border-rose-500/30 dark:bg-rose-500/10';
        default:
            return 'border-border/60 bg-muted/15';
    }
}

export function CrewEmployeeOperationalStatus({
    status,
    activeOnVessel,
    className,
}: CrewEmployeeOperationalStatusProps): ReactElement | null {
    if (!status) {
        return null;
    }

    const statusCode = status.status;
    const vesselName =
        status.vessel_name ??
        status.current_vessel ??
        activeOnVessel?.vessel_name ??
        null;
    const assignmentNo = status.assignment_no ?? activeOnVessel?.assignment_no;
    const daysInPhase = status.days_in_phase;
    const sinceText = activeOnVessel?.actual_start_display
        ? activeOnVessel.actual_start_display
        : status.since
          ? formatDisplayDateTime(status.since)
          : null;

    if (statusCode === 'on_vessel') {
        return (
            <div
                data-slot="employee-operational-status"
                className={cn(
                    'rounded-xl border border-amber-500/50 bg-amber-500/15 p-4 text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100',
                    className,
                )}
            >
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-amber-500/25 p-2 text-amber-900 dark:text-amber-200">
                        <AlertTriangle
                            className="size-5 shrink-0"
                            aria-hidden
                        />
                    </div>
                    <div className="flex-1 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                className="border-amber-500/60 bg-amber-500/20 font-semibold tracking-wider text-amber-950 uppercase dark:text-amber-100"
                            >
                                <Ship className="mr-1 size-3" aria-hidden />
                                On Vessel
                            </Badge>
                            {assignmentNo ? (
                                <span className="text-xs font-medium text-amber-900/80 dark:text-amber-200/80">
                                    {assignmentNo}
                                </span>
                            ) : null}
                        </div>

                        <div>
                            <p className="text-base font-semibold tracking-tight text-amber-950 dark:text-amber-50">
                                {vesselName ?? 'Active Vessel'}
                                {assignmentNo ? ` · ${assignmentNo}` : ''}
                            </p>
                            <p className="text-xs text-amber-900/90 dark:text-amber-200/90">
                                P4 started {sinceText ?? '—'}
                                {daysInPhase != null
                                    ? ` · ${daysInPhase} ${daysInPhase === 1 ? 'day' : 'days'} onboard`
                                    : ''}
                            </p>
                        </div>

                        <div className="rounded-lg border border-amber-500/30 bg-amber-500/20 p-2.5 text-xs text-amber-950 dark:text-amber-100">
                            <p className="font-medium">
                                Already has an active vessel assignment.
                            </p>
                            <p className="mt-0.5 text-amber-900/90 dark:text-amber-200/90">
                                Creating another assignment may produce
                                conflicting operational history. Use Transfer
                                Vessel if this is a vessel change.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (statusCode === 'join_standby') {
        return (
            <div
                data-slot="employee-operational-status"
                className={cn(
                    'rounded-xl border border-sky-500/40 bg-sky-500/10 p-4 text-sky-950 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100',
                    className,
                )}
            >
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-sky-500/20 p-2 text-sky-800 dark:text-sky-200">
                        <Anchor className="size-5 shrink-0" aria-hidden />
                    </div>
                    <div className="flex-1 space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                className="border-sky-500/50 bg-sky-500/20 font-semibold tracking-wider text-sky-950 uppercase dark:text-sky-100"
                            >
                                <Clock className="mr-1 size-3" aria-hidden />
                                Join Standby · P2A
                            </Badge>
                            {assignmentNo ? (
                                <span className="text-xs font-medium text-sky-900/80 dark:text-sky-200/80">
                                    {assignmentNo}
                                </span>
                            ) : null}
                        </div>

                        <p className="text-sm font-medium text-sky-950 dark:text-sky-100">
                            Currently in Join Standby
                            {assignmentNo ? ` for ${assignmentNo}` : ''}
                            {daysInPhase != null
                                ? ` · ${daysInPhase} ${daysInPhase === 1 ? 'day' : 'days'}`
                                : ''}
                        </p>

                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-sky-900/80 dark:text-sky-200/80">
                            {sinceText ? (
                                <span>Standby since: {sinceText}</span>
                            ) : null}
                            {status.planned_next_date ? (
                                <span>
                                    Planned join:{' '}
                                    {formatDisplayDate(
                                        status.planned_next_date,
                                    )}
                                </span>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (statusCode === 'demob_standby') {
        return (
            <div
                data-slot="employee-operational-status"
                className={cn(
                    'rounded-xl border border-indigo-500/40 bg-indigo-500/10 p-4 text-indigo-950 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100',
                    className,
                )}
            >
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-indigo-500/20 p-2 text-indigo-800 dark:text-indigo-200">
                        <Compass className="size-5 shrink-0" aria-hidden />
                    </div>
                    <div className="flex-1 space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                className="border-indigo-500/50 bg-indigo-500/20 font-semibold tracking-wider text-indigo-950 uppercase dark:text-indigo-100"
                            >
                                <Clock className="mr-1 size-3" aria-hidden />
                                Demob Standby · P5
                            </Badge>
                            {assignmentNo ? (
                                <span className="text-xs font-medium text-indigo-900/80 dark:text-indigo-200/80">
                                    {assignmentNo}
                                </span>
                            ) : null}
                        </div>

                        <p className="text-sm font-medium text-indigo-950 dark:text-indigo-100">
                            Currently in Demobilisation Standby
                            {assignmentNo ? ` for ${assignmentNo}` : ''}
                            {daysInPhase != null
                                ? ` · ${daysInPhase} ${daysInPhase === 1 ? 'day' : 'days'}`
                                : ''}
                        </p>

                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-indigo-900/80 dark:text-indigo-200/80">
                            {sinceText ? (
                                <span>Standby since: {sinceText}</span>
                            ) : null}
                            {status.planned_next_date ? (
                                <span>
                                    Planned travel:{' '}
                                    {formatDisplayDate(
                                        status.planned_next_date,
                                    )}
                                </span>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (statusCode === 'in_home') {
        const inHomeDays = status.in_home_days ?? status.days_in_phase;

        return (
            <div
                data-slot="employee-operational-status"
                className={cn(
                    'rounded-xl border border-emerald-500/35 bg-emerald-500/10 p-3.5 text-emerald-950 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-100',
                    className,
                )}
            >
                <div className="flex items-center gap-3">
                    <div className="rounded-lg bg-emerald-500/20 p-2 text-emerald-700 dark:text-emerald-300">
                        <Home className="size-4 shrink-0" aria-hidden />
                    </div>
                    <div className="flex-1 space-y-0.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                className="border-emerald-500/40 bg-emerald-500/20 font-semibold tracking-wider text-emerald-950 uppercase dark:text-emerald-100"
                            >
                                <CheckCircle2
                                    className="mr-1 size-3"
                                    aria-hidden
                                />
                                {inHomeDays != null
                                    ? `In Home · ${inHomeDays}d`
                                    : 'Available'}
                            </Badge>
                            <span className="text-xs text-emerald-900/80 dark:text-emerald-200/80">
                                No active operational conflict
                            </span>
                        </div>
                        <p className="text-xs text-emerald-900/70 dark:text-emerald-200/70">
                            Available for new mobilisation cycle.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    // Other active phases: P0 Pre-Mobilisation, P1 Travel In, P2B Training, P3 Ready to Join, P6 Home/Redeploy
    const phaseIcons: Record<string, typeof Plane> = {
        travel_in: Plane,
        training: GraduationCap,
        pre_mobilisation: Clock,
        ready_to_join: Anchor,
        home_redeploy: Home,
    };
    const PhaseIcon = phaseIcons[statusCode] ?? AlertCircle;

    return (
        <div
            data-slot="employee-operational-status"
            className={cn(
                'rounded-xl border border-sky-500/30 bg-sky-500/5 p-4 text-sky-950 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                <div className="rounded-lg bg-sky-500/15 p-2 text-sky-800 dark:text-sky-200">
                    <PhaseIcon className="size-5 shrink-0" aria-hidden />
                </div>
                <div className="flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="outline"
                            className="border-sky-500/40 bg-sky-500/15 font-semibold tracking-wider text-sky-950 uppercase dark:text-sky-100"
                        >
                            {status.label}
                            {status.current_phase
                                ? ` · ${status.current_phase.toUpperCase()}`
                                : ''}
                        </Badge>
                        {assignmentNo ? (
                            <span className="text-xs font-medium text-sky-900/80 dark:text-sky-200/80">
                                {assignmentNo}
                            </span>
                        ) : null}
                    </div>

                    <p className="text-sm font-medium text-sky-950 dark:text-sky-100">
                        Currently in {status.label}
                        {assignmentNo ? ` (${assignmentNo})` : ''}
                        {daysInPhase != null
                            ? ` · ${daysInPhase} ${daysInPhase === 1 ? 'day' : 'days'} in phase`
                            : ''}
                    </p>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-sky-900/80 dark:text-sky-200/80">
                        {sinceText ? <span>Started: {sinceText}</span> : null}
                        {status.planned_next_date ? (
                            <span>
                                Planned next:{' '}
                                {formatDisplayDate(status.planned_next_date)}
                            </span>
                        ) : null}
                    </div>

                    {status.warning ? (
                        <p className="text-xs font-medium text-amber-700 dark:text-amber-300">
                            {status.warning}
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
