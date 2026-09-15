import { AlertTriangle } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { shouldShowPlanningTransferGuidance } from '@/features/organization/crew/lib/planning-start-conflict';
import type {
    ActiveOnVesselAssignment,
    CrewPlanningStartContext,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import { show as showAssignment } from '@/routes/organization/crew-assignments';

export function PlanningStartActiveAssignmentConflict({
    planningContext,
    employeeStatus,
    activeOnVessel,
    destinationVesselId,
    canViewAssignment,
}: {
    planningContext: CrewPlanningStartContext;
    employeeStatus: EmployeeOperationalStatus;
    activeOnVessel: ActiveOnVesselAssignment | null;
    destinationVesselId: number | null;
    canViewAssignment: boolean;
}): ReactElement {
    const assignmentId =
        employeeStatus.assignment_id ?? activeOnVessel?.assignment_id ?? null;
    const assignmentNo =
        employeeStatus.assignment_no ?? activeOnVessel?.assignment_no ?? null;
    const currentVesselName =
        activeOnVessel?.vessel_name ??
        employeeStatus.vessel_name ??
        employeeStatus.current_vessel ??
        'Unknown vessel';
    const isOnVessel = employeeStatus.status === 'on_vessel';
    const showTransferGuidance = shouldShowPlanningTransferGuidance(
        employeeStatus,
        activeOnVessel,
        destinationVesselId,
    );

    return (
        <section
            data-slot="planning-start-active-assignment-conflict"
            className="rounded-xl border border-amber-500/50 bg-amber-500/10 p-5 text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100"
        >
            <div className="flex items-start gap-3">
                <div className="rounded-lg bg-amber-500/25 p-2 text-amber-900 dark:text-amber-200">
                    <AlertTriangle className="size-5 shrink-0" aria-hidden />
                </div>
                <div className="space-y-3 text-sm">
                    <div>
                        <h2 className="text-base font-semibold tracking-tight">
                            {isOnVessel
                                ? `${planningContext.employee_name} is already On Vessel`
                                : `${planningContext.employee_name} already has an active Crew Assignment`}
                        </h2>
                        {isOnVessel ? (
                            <p className="mt-1 text-xs text-amber-900/85 dark:text-amber-200/85">
                                Active P4 On Vessel
                            </p>
                        ) : (
                            <p className="mt-1 text-xs text-amber-900/85 dark:text-amber-200/85">
                                {employeeStatus.label}
                                {employeeStatus.current_phase
                                    ? ` · ${employeeStatus.current_phase.toUpperCase()}`
                                    : ''}
                            </p>
                        )}
                    </div>

                    <dl className="grid gap-2 text-xs sm:grid-cols-2">
                        {assignmentNo ? (
                            <div>
                                <dt className="font-medium tracking-wide text-amber-900/70 uppercase dark:text-amber-200/70">
                                    Current Assignment
                                </dt>
                                <dd className="mt-0.5 font-medium">
                                    {assignmentNo}
                                </dd>
                            </div>
                        ) : null}
                        {isOnVessel ? (
                            <div>
                                <dt className="font-medium tracking-wide text-amber-900/70 uppercase dark:text-amber-200/70">
                                    Current Vessel
                                </dt>
                                <dd className="mt-0.5 font-medium">
                                    {currentVesselName}
                                </dd>
                            </div>
                        ) : null}
                        <div
                            className={isOnVessel ? undefined : 'sm:col-span-2'}
                        >
                            <dt className="font-medium tracking-wide text-amber-900/70 uppercase dark:text-amber-200/70">
                                Planned Destination
                            </dt>
                            <dd className="mt-0.5 font-medium">
                                {planningContext.vessel_name}
                            </dd>
                        </div>
                    </dl>

                    <p className="text-sm leading-relaxed text-amber-950/90 dark:text-amber-100/90">
                        {isOnVessel
                            ? 'This crew member already has an active On Vessel assignment. Do not start another assignment.'
                            : 'This employee already has an active Crew Assignment. Continue the existing assignment instead of starting another one.'}
                    </p>

                    {showTransferGuidance ? (
                        <p className="text-sm leading-relaxed text-amber-950/90 dark:text-amber-100/90">
                            If {planningContext.employee_name} is moving
                            directly from {currentVesselName} to{' '}
                            {planningContext.vessel_name}, use the existing
                            Transfer Vessel workflow from the current
                            assignment.
                        </p>
                    ) : null}

                    {canViewAssignment && assignmentId ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-9 rounded-lg border-amber-500/40 bg-background/80 px-4"
                            onClick={() => routerVisitAssignment(assignmentId)}
                        >
                            Open Current Assignment
                        </Button>
                    ) : (
                        <p className="text-xs font-medium text-amber-900/85 dark:text-amber-200/85">
                            Ask an authorized Operations user to review the
                            existing assignment.
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}

function routerVisitAssignment(assignmentId: number): void {
    window.open(showAssignment.url(assignmentId), '_blank', 'noopener');
}
