import type { ReactElement } from 'react';
import {
    GuidanceAdvisory,
    GuidanceEmployeeIdentity,
    GuidanceWarning,
    MovementGuidanceHeader,
    WhyStartBlockedHelp,
} from '@/features/organization/crew/components/crew-movement-guidance-primitives';
import { CrewMovementJourneyIndicator } from '@/features/organization/crew/components/crew-movement-journey-indicator';
import { buildAssignmentEditGuidance } from '@/features/organization/crew/lib/assignment-edit-guidance';
import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

export function CrewAssignmentEditContextPanel({
    assignment,
    formData,
    formOptions,
    permissions,
    className,
}: {
    assignment: CrewAssignmentDetail;
    formData: CrewAssignmentFormData;
    formOptions: CrewAssignmentFormOptions;
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'perform_movement' | 'view_planning'
    >;
    className?: string;
}): ReactElement {
    const employee = assignment.employee;
    const employeeOption = employee
        ? formOptions.employees.find((item) => item.id === employee.id)
        : null;
    const rankName =
        formOptions.ranks.find((rank) => rank.id === formData.rank_id)?.name ??
        assignment.rank?.name ??
        null;

    const guidance = buildAssignmentEditGuidance({
        assignment,
        formData,
        vessels: formOptions.vessels,
        ranks: formOptions.ranks,
        permissions,
    });

    return (
        <aside
            className={cn('rounded-xl border glass-card p-3.5', className)}
            aria-label="Editing guidance"
        >
            <MovementGuidanceHeader
                subtitle={`Editing ${assignment.assignment_no}`}
            />

            <div className="mt-2.5 space-y-3">
                {employee ? (
                    <GuidanceEmployeeIdentity
                        name={employee.name}
                        employeeNo={employee.employee_no}
                        rankName={rankName}
                        nationalityName={employeeOption?.nationality_name}
                        image={employee.image ?? employeeOption?.image}
                    />
                ) : null}

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

                    {assignment.current_phase?.code ? (
                        <CrewMovementJourneyIndicator
                            currentPhaseCode={assignment.current_phase.code}
                        />
                    ) : null}

                    <p className="text-xs leading-relaxed text-muted-foreground">
                        {guidance.explanation}
                    </p>

                    {guidance.safeToUpdate ? (
                        <div className="rounded-lg border border-border/60 bg-muted/10 px-2.5 py-2 text-xs">
                            <p className="font-semibold">Safe to update here</p>
                            <p className="mt-0.5 text-muted-foreground">
                                {guidance.safeToUpdate}
                            </p>
                        </div>
                    ) : null}

                    {guidance.destinationNote ? (
                        <GuidanceAdvisory
                            title="Destination changed"
                            message={guidance.destinationNote}
                        />
                    ) : null}

                    {guidance.planningChanges.length > 0 ? (
                        <div className="rounded-lg border border-amber-500/35 bg-amber-500/10 px-2.5 py-2 text-xs text-amber-950 dark:text-amber-100">
                            <p className="font-semibold tracking-wide uppercase">
                                Planning changes
                            </p>
                            <dl className="mt-1.5 space-y-1.5">
                                {guidance.planningChanges.map((change) => (
                                    <div key={change.field}>
                                        <dt className="font-medium">
                                            {change.label}
                                        </dt>
                                        <dd className="text-muted-foreground">
                                            {change.from} → {change.to}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {guidance.planningSyncNote ? (
                                <p className="mt-2 leading-relaxed opacity-90">
                                    {guidance.planningSyncNote}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    {guidance.dateAdvisories.map((advisory) =>
                        advisory.severity === 'error' ? (
                            <GuidanceWarning
                                key={`${advisory.field}-${advisory.message}`}
                                message={advisory.message}
                            />
                        ) : (
                            <GuidanceAdvisory
                                key={`${advisory.field}-${advisory.message}`}
                                title="Date advisory"
                                message={advisory.message}
                            />
                        ),
                    )}

                    <WhyStartBlockedHelp triggerLabel="What belongs in Movement Actions?">
                        <p>
                            {guidance.movementActionsNote ??
                                'Actual operational events — arrival, joining, training completion, disembarkation, return home, redeploy, and vessel transfer — are recorded through Movement Actions on the assignment. This form updates planning fields only.'}
                        </p>
                    </WhyStartBlockedHelp>
                </div>
            </div>
        </aside>
    );
}
