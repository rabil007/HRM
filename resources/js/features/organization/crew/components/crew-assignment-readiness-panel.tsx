import type { ReactElement } from 'react';
import { openTransferVessel } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import {
    GuidanceActionButton,
    GuidanceAdvisory,
    GuidanceEmployeeIdentity,
    GuidanceWarning,
    MovementGuidanceHeader,
    WhyStartBlockedHelp,
} from '@/features/organization/crew/components/crew-movement-guidance-primitives';
import { CrewMovementJourneyIndicator } from '@/features/organization/crew/components/crew-movement-journey-indicator';
import {
    buildAssignmentReadinessGuidance,
    readinessAdvisoryClassName,
} from '@/features/organization/crew/lib/assignment-readiness-guidance';
import type { ReadinessAction } from '@/features/organization/crew/lib/assignment-readiness-guidance';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

function ReadinessEmptyState(): ReactElement {
    return (
        <div
            data-slot="assignment-readiness-empty"
            className="rounded-lg border border-dashed border-border/70 bg-muted/10 px-3 py-3 text-sm text-muted-foreground"
        >
            Select an employee to view movement guidance.
        </div>
    );
}

function resolveActionHref(
    action: ReadinessAction,
    assignmentId: number | null,
): string | null {
    if (assignmentId === null) {
        return action.key === 'plan_future' ? crewPlanningIndex.url() : null;
    }

    switch (action.key) {
        case 'edit_mobilisation':
            return editAssignment.url(assignmentId);
        case 'plan_future':
            return crewPlanningIndex.url();
        case 'continue_assignment':
        case 'open_assignment':
        case 'join_vessel':
        case 'return_home':
        case 'redeploy':
        case 'close_assignment':
        case 'cancel_assignment':
        case 'record_arrival':
            return showAssignment.url(assignmentId);
        default:
            return null;
    }
}

export function CrewAssignmentReadinessPanel({
    employeeId,
    formOptions,
    permissions,
    destinationVesselId = null,
    plannedJoinAt = null,
    transferPrefill,
    planningEmployeeName = null,
    planningRankName = null,
    className,
}: {
    employeeId: number | null;
    formOptions: CrewAssignmentCreateFormOptions;
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'view' | 'update' | 'perform_movement' | 'cancel' | 'view_planning'
    >;
    destinationVesselId?: number | null;
    plannedJoinAt?: string | null;
    transferPrefill?: {
        vessel_id?: number | null;
        rank_id?: number | null;
        client_id?: number | null;
    };
    planningEmployeeName?: string | null;
    planningRankName?: string | null;
    className?: string;
}): ReactElement {
    const employee =
        employeeId != null
            ? formOptions.employees.find((item) => item.id === employeeId)
            : null;
    const status =
        employeeId != null
            ? (formOptions.employee_status_by_employee?.[String(employeeId)] ??
              null)
            : null;
    const activeOnVessel =
        employeeId != null
            ? (formOptions.active_on_vessel_by_employee?.[String(employeeId)] ??
              null)
            : null;
    const rankName =
        planningRankName ??
        (employee?.rank_id != null
            ? (formOptions.ranks.find((rank) => rank.id === employee.rank_id)
                  ?.name ?? null)
            : null);
    const destinationVesselName =
        destinationVesselId != null
            ? (formOptions.vessels.find(
                  (vessel) => vessel.id === destinationVesselId,
              )?.name ?? null)
            : null;
    const employeeName =
        employee?.name ?? planningEmployeeName ?? 'This employee';
    const assignmentId =
        status?.assignment_id ?? activeOnVessel?.assignment_id ?? null;

    const guidance = buildAssignmentReadinessGuidance({
        status,
        activeOnVessel,
        destinationVesselId,
        destinationVesselName,
        plannedJoinAt,
        employeeName,
        permissions,
        vessels: formOptions.vessels,
        maxHomeDays: formOptions.max_home_days,
    });

    return (
        <aside
            className={cn('rounded-xl border glass-card p-3.5', className)}
            aria-label="Movement Guidance"
            aria-live="polite"
        >
            <MovementGuidanceHeader />

            <div className="mt-2.5 space-y-3">
                {!employee && !planningEmployeeName ? (
                    <ReadinessEmptyState />
                ) : (
                    <>
                        <GuidanceEmployeeIdentity
                            name={employeeName}
                            employeeNo={employee?.employee_no}
                            rankName={rankName}
                            nationalityName={employee?.nationality_name}
                            image={employee?.image}
                        />

                        {guidance ? (
                            <div className="space-y-2">
                                <div>
                                    <p className="text-xs font-semibold tracking-wide text-foreground">
                                        {guidance.phaseLabel}
                                    </p>
                                    {guidance.summaryLine ? (
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {guidance.summaryLine}
                                        </p>
                                    ) : null}
                                </div>

                                {status?.current_phase ? (
                                    <CrewMovementJourneyIndicator
                                        currentPhaseCode={status.current_phase}
                                    />
                                ) : null}

                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    {guidance.explanation}
                                </p>

                                {guidance.actions.length > 0 ? (
                                    <div className="space-y-1.5">
                                        {guidance.actions.map((action) => (
                                            <GuidanceActionButton
                                                key={`${action.key}-${action.label}`}
                                                action={action}
                                                href={resolveActionHref(
                                                    action,
                                                    assignmentId,
                                                )}
                                                onClick={
                                                    action.kind ===
                                                        'transfer' &&
                                                    activeOnVessel
                                                        ? () =>
                                                              openTransferVessel(
                                                                  activeOnVessel,
                                                                  transferPrefill ??
                                                                      {},
                                                              )
                                                        : undefined
                                                }
                                            />
                                        ))}
                                    </div>
                                ) : null}

                                {guidance.transferPermissionNote ? (
                                    <p className="text-[11px] text-muted-foreground">
                                        {guidance.transferPermissionNote}
                                    </p>
                                ) : null}

                                {guidance.warning ? (
                                    <GuidanceWarning
                                        message={guidance.warning}
                                    />
                                ) : null}

                                {guidance.destinationAdvisory ? (
                                    <div
                                        className={cn(
                                            'rounded-lg border px-2.5 py-2 text-xs',
                                            readinessAdvisoryClassName(
                                                guidance.destinationAdvisory
                                                    .severity,
                                            ),
                                        )}
                                    >
                                        <p className="font-semibold">
                                            {guidance.destinationAdvisory.title}
                                        </p>
                                        <p className="mt-0.5 leading-relaxed opacity-90">
                                            {
                                                guidance.destinationAdvisory
                                                    .message
                                            }
                                        </p>
                                    </div>
                                ) : null}

                                {guidance.plannedDateAdvisory ? (
                                    <div
                                        className={cn(
                                            'rounded-lg border px-2.5 py-2 text-xs',
                                            readinessAdvisoryClassName(
                                                guidance.plannedDateAdvisory
                                                    .severity,
                                            ),
                                        )}
                                    >
                                        <p className="font-semibold">
                                            {guidance.plannedDateAdvisory.title}
                                        </p>
                                        <p className="mt-0.5 leading-relaxed opacity-90">
                                            {
                                                guidance.plannedDateAdvisory
                                                    .message
                                            }
                                        </p>
                                    </div>
                                ) : null}

                                {guidance.showWhyBlocked ? (
                                    <WhyStartBlockedHelp />
                                ) : null}
                            </div>
                        ) : null}

                        {status?.has_active_assignment &&
                        !permissions.view &&
                        !guidance ? (
                            <GuidanceAdvisory
                                title="Active assignment"
                                message="Active assignment details are restricted for your role."
                            />
                        ) : null}
                    </>
                )}
            </div>
        </aside>
    );
}
