import {
    AlertTriangle,
    ArrowRight,
    ChevronDown,
    HelpCircle,
    Info,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { openTransferVessel } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { CrewEmployeeStatusBadge } from '@/features/organization/crew/components/crew-employee-status-badge';
import { CrewMovementJourneyIndicator } from '@/features/organization/crew/components/crew-movement-journey-indicator';
import {
    buildAssignmentReadinessGuidance,
    readinessAlertClassName,
    readinessSeverityClassName,
    type ReadinessAction,
    type ReadinessActionKey,
} from '@/features/organization/crew/lib/assignment-readiness-guidance';
import { isHomeAvailabilityStatus } from '@/features/organization/crew/lib/assignment-readiness';
import type {
    ActiveOnVesselAssignment,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import {
    formatDisplayDate,
    formatDisplayDateTimeInTimezone,
} from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

function ReadinessSection({
    title,
    children,
    className,
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}): ReactElement {
    return (
        <section className={cn('space-y-2', className)}>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </p>
            {children}
        </section>
    );
}

function ReadinessEmptyState(): ReactElement {
    return (
        <div
            data-slot="assignment-readiness-empty"
            className="rounded-lg border border-dashed border-border/70 bg-muted/10 px-3 py-4 text-sm text-muted-foreground"
        >
            Select an employee to view their crew movement journey, current
            assignment context, and operational guidance.
        </div>
    );
}

function WhyBlockedHelp(): ReactElement {
    return (
        <Collapsible>
            <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-lg border border-border/60 bg-muted/10 px-3 py-2 text-left text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                <HelpCircle className="size-3.5 shrink-0" aria-hidden />
                <span className="flex-1">
                    Why can&apos;t I start another active assignment?
                </span>
                <ChevronDown
                    className="size-3.5 shrink-0 transition-transform [[data-state=open]_&]:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-2 rounded-lg border border-border/60 bg-muted/10 px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
                <p>
                    OMS-HRM keeps one active mobilisation cycle per employee.
                    Starting another active assignment could create overlapping
                    or conflicting operational records.
                </p>
                <ul className="mt-2 list-disc space-y-1 pl-4">
                    <li>Duplicate or ambiguous current vessel</li>
                    <li>Overlapping crew payroll movement days</li>
                    <li>Overlapping sea-service history</li>
                    <li>Incorrect vessel manning</li>
                    <li>Conflicting crew status</li>
                    <li>Inconsistent accommodation or movement history</li>
                </ul>
            </CollapsibleContent>
        </Collapsible>
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

function GuidanceActionButton({
    action,
    assignmentId,
    activeOnVessel,
    transferPrefill,
    onTransfer,
}: {
    action: ReadinessAction;
    assignmentId: number | null;
    activeOnVessel: ActiveOnVesselAssignment | null;
    transferPrefill?: {
        vessel_id?: number | null;
        rank_id?: number | null;
        client_id?: number | null;
    };
    onTransfer?: () => void;
}): ReactElement | null {
    if (action.kind === 'transfer') {
        if (!activeOnVessel) {
            return null;
        }

        return (
            <Button
                type="button"
                variant={action.emphasis === 'primary' ? 'default' : 'outline'}
                size="sm"
                className="h-auto min-h-8 w-full justify-start gap-2 rounded-lg px-2.5 py-2 text-left text-xs whitespace-normal"
                onClick={() => {
                    onTransfer?.();
                    openTransferVessel(activeOnVessel, transferPrefill ?? {});
                }}
            >
                <ArrowRight className="size-3 shrink-0" aria-hidden />
                <span>
                    <span className="block font-semibold">{action.label}</span>
                    {action.description ? (
                        <span className="mt-0.5 block font-normal opacity-90">
                            {action.description}
                        </span>
                    ) : null}
                </span>
            </Button>
        );
    }

    const href = resolveActionHref(action, assignmentId);

    if (!href) {
        return null;
    }

    return (
        <Button
            type="button"
            variant={action.emphasis === 'primary' ? 'default' : 'outline'}
            size="sm"
            className="h-auto min-h-8 w-full justify-start gap-2 rounded-lg px-2.5 py-2 text-left text-xs whitespace-normal"
            onClick={() => window.open(href, '_blank', 'noopener')}
        >
            <ArrowRight className="size-3 shrink-0" aria-hidden />
            <span>
                <span className="block font-semibold">{action.label}</span>
                {action.description ? (
                    <span className="mt-0.5 block font-normal opacity-90">
                        {action.description}
                    </span>
                ) : null}
            </span>
        </Button>
    );
}

function intentActionAvailable(
    actionKey: ReadinessActionKey,
    actions: ReadinessAction[],
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'perform_movement' | 'view_planning'
    >,
    activeOnVessel: ActiveOnVesselAssignment | null,
): boolean {
    if (actionKey === 'plan_future') {
        return permissions.view_planning;
    }

    if (actionKey === 'transfer_vessel') {
        return (
            permissions.perform_movement &&
            activeOnVessel?.can_transfer === true &&
            actions.some((action) => action.key === 'transfer_vessel')
        );
    }

    return actions.some((action) => action.key === actionKey);
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
    bulkMode = false,
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
    bulkMode?: boolean;
    className?: string;
}): ReactElement {
    if (bulkMode) {
        return (
            <aside
                className={cn('rounded-xl border glass-card p-4', className)}
                aria-label="Movement Guidance"
            >
                <PanelHeader />
                <div className="mt-3 rounded-lg border border-dashed border-border/70 bg-muted/10 px-3 py-4 text-sm text-muted-foreground">
                    Movement guidance is available when starting one crew member
                    at a time. Review each bulk row status in the table below.
                </div>
            </aside>
        );
    }

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
    const companyTimezone = formOptions.company_timezone ?? 'UTC';
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

    const sinceRaw = activeOnVessel?.actual_start_at ?? status?.since ?? null;
    const sinceText = sinceRaw
        ? formatDisplayDateTimeInTimezone(sinceRaw, companyTimezone)
        : null;
    const vesselName =
        status?.vessel_name ??
        status?.current_vessel ??
        activeOnVessel?.vessel_name ??
        null;

    const showAssignmentSection =
        status?.has_active_assignment &&
        (status.assignment_no || vesselName || permissions.view);

    return (
        <aside
            className={cn('rounded-xl border glass-card p-4', className)}
            aria-label="Movement Guidance"
            aria-live="polite"
        >
            <PanelHeader />

            <div className="mt-3 space-y-4">
                {!employee && !planningEmployeeName ? (
                    <ReadinessEmptyState />
                ) : (
                    <>
                        <div
                            data-slot="assignment-readiness-employee"
                            className="flex items-start gap-3"
                        >
                            {employee ? (
                                <EmployeeAvatar
                                    name={employee.name}
                                    image={employee.image}
                                    size="md"
                                />
                            ) : (
                                <div className="flex size-12 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground">
                                    {(planningEmployeeName ?? '?')
                                        .slice(0, 1)
                                        .toUpperCase()}
                                </div>
                            )}
                            <div className="min-w-0 flex-1 space-y-1">
                                <p className="truncate text-sm font-semibold">
                                    {employeeName}
                                </p>
                                {employee?.employee_no ? (
                                    <p className="font-mono text-[11px] text-muted-foreground">
                                        {employee.employee_no}
                                    </p>
                                ) : null}
                                {rankName ? (
                                    <p className="text-xs text-muted-foreground">
                                        {rankName}
                                    </p>
                                ) : null}
                                {employee?.nationality_name ? (
                                    <p className="text-xs text-muted-foreground">
                                        {employee.nationality_name}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        {status ? (
                            <CrewEmployeeStatusBadge status={status} />
                        ) : null}

                        {status?.current_phase ? (
                            <ReadinessSection title="Current journey">
                                <CrewMovementJourneyIndicator
                                    currentPhaseCode={status.current_phase}
                                />
                            </ReadinessSection>
                        ) : null}

                        {showAssignmentSection ? (
                            <ReadinessSection title="Current assignment">
                                <div className="space-y-1 rounded-lg border border-border/60 bg-muted/15 px-3 py-2.5 text-sm">
                                    {status?.assignment_no ? (
                                        <p className="font-mono text-xs font-semibold">
                                            {status.assignment_no}
                                        </p>
                                    ) : status?.has_active_assignment &&
                                      !permissions.view ? (
                                        <p className="text-xs text-muted-foreground">
                                            Active assignment details are
                                            restricted for your role.
                                        </p>
                                    ) : null}
                                    {vesselName ? (
                                        <p className="text-sm">
                                            Vessel:{' '}
                                            <span className="font-medium">
                                                {vesselName}
                                            </span>
                                        </p>
                                    ) : null}
                                    {status?.current_phase && status.label ? (
                                        <p className="text-xs text-muted-foreground">
                                            Phase:{' '}
                                            {status.current_phase.toUpperCase()}{' '}
                                            · {status.label}
                                        </p>
                                    ) : null}
                                    {sinceText ? (
                                        <p className="text-xs text-muted-foreground">
                                            Phase started: {sinceText}
                                        </p>
                                    ) : null}
                                    {status?.days_in_phase != null ? (
                                        <p className="text-xs text-muted-foreground">
                                            {status.days_in_phase}{' '}
                                            {status.days_in_phase === 1
                                                ? 'day'
                                                : 'days'}{' '}
                                            in current phase
                                        </p>
                                    ) : null}
                                    {status?.planned_next_date ? (
                                        <p className="text-xs text-muted-foreground">
                                            Planned next:{' '}
                                            {formatDisplayDate(
                                                status.planned_next_date,
                                            )}
                                        </p>
                                    ) : null}
                                    {assignmentId && status?.assignment_no ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="mt-2 h-7 gap-1.5 rounded-lg px-2.5 text-xs"
                                            onClick={() =>
                                                window.open(
                                                    showAssignment.url(
                                                        assignmentId,
                                                    ),
                                                    '_blank',
                                                    'noopener',
                                                )
                                            }
                                        >
                                            <ArrowRight
                                                className="size-3"
                                                aria-hidden
                                            />
                                            Open {status.assignment_no}
                                        </Button>
                                    ) : null}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {isHomeAvailabilityStatus(
                            status?.availability_status,
                        ) && !status?.has_active_assignment ? (
                            <ReadinessSection title="Home availability">
                                <div className="space-y-1 rounded-lg border border-border/60 bg-muted/15 px-3 py-2.5 text-sm">
                                    <p className="font-medium">
                                        Home for{' '}
                                        {status?.days_at_home ??
                                            status?.in_home_days ??
                                            '—'}{' '}
                                        days
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Availability rule:{' '}
                                        {formOptions.max_home_days ?? '—'} days
                                    </p>
                                    {status?.availability_detail ? (
                                        <p
                                            className={cn(
                                                'text-xs font-medium',
                                                status.availability_status ===
                                                    'over_limit'
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {status.availability_detail}
                                        </p>
                                    ) : null}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance ? (
                            <ReadinessSection title="What this means">
                                <div
                                    className={cn(
                                        'rounded-lg border px-3 py-2.5 text-xs',
                                        readinessSeverityClassName(
                                            guidance.severity,
                                        ),
                                    )}
                                >
                                    <p className="font-semibold">
                                        {guidance.title}
                                    </p>
                                    <p className="mt-1 leading-relaxed opacity-90">
                                        {guidance.description}
                                    </p>
                                    {guidance.guidance ? (
                                        <p className="mt-2 leading-relaxed opacity-90">
                                            {guidance.guidance}
                                        </p>
                                    ) : null}
                                    {guidance.secondaryGuidance ? (
                                        <p className="mt-2 leading-relaxed opacity-90">
                                            {guidance.secondaryGuidance}
                                        </p>
                                    ) : null}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance?.destinationAlert ? (
                            <ReadinessSection title="Destination">
                                <div
                                    className={cn(
                                        'rounded-lg border px-3 py-2.5 text-xs',
                                        readinessAlertClassName(
                                            guidance.destinationAlert.severity,
                                        ),
                                    )}
                                >
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle
                                            className="mt-0.5 size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        <div>
                                            <p className="font-semibold">
                                                {
                                                    guidance.destinationAlert
                                                        .title
                                                }
                                            </p>
                                            <p className="mt-0.5 leading-relaxed opacity-90">
                                                {
                                                    guidance.destinationAlert
                                                        .message
                                                }
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance?.plannedDateAlert ? (
                            <ReadinessSection title="Planned dates">
                                <div
                                    className={cn(
                                        'rounded-lg border px-3 py-2.5 text-xs',
                                        readinessAlertClassName(
                                            guidance.plannedDateAlert.severity,
                                        ),
                                    )}
                                >
                                    <div className="flex items-start gap-2">
                                        <Info
                                            className="mt-0.5 size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        <div>
                                            <p className="font-semibold">
                                                {
                                                    guidance.plannedDateAlert
                                                        .title
                                                }
                                            </p>
                                            <p className="mt-0.5 leading-relaxed opacity-90">
                                                {
                                                    guidance.plannedDateAlert
                                                        .message
                                                }
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance?.intentOptions &&
                        guidance.intentOptions.length > 0 ? (
                            <ReadinessSection title="What are you trying to do?">
                                <div className="space-y-2">
                                    {guidance.intentOptions.map((option) => {
                                        const available = intentActionAvailable(
                                            option.actionKey,
                                            guidance.actions,
                                            permissions,
                                            activeOnVessel,
                                        );

                                        return (
                                            <div
                                                key={option.question}
                                                className="rounded-lg border border-border/60 bg-muted/10 px-3 py-2 text-xs"
                                            >
                                                <p className="font-semibold">
                                                    {option.question}
                                                </p>
                                                <p className="mt-0.5 leading-relaxed text-muted-foreground">
                                                    → {option.hint}
                                                </p>
                                                {!available ? (
                                                    <p className="mt-1 text-[11px] text-muted-foreground/80">
                                                        This action is not
                                                        available with your
                                                        current permissions.
                                                    </p>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance && guidance.actions.length > 0 ? (
                            <ReadinessSection title="Recommended actions">
                                <div className="space-y-2">
                                    {guidance.actions.map((action) => (
                                        <GuidanceActionButton
                                            key={`${action.key}-${action.label}`}
                                            action={action}
                                            assignmentId={assignmentId}
                                            activeOnVessel={activeOnVessel}
                                            transferPrefill={transferPrefill}
                                        />
                                    ))}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance?.attention ? (
                            <ReadinessSection title="Attention">
                                <div className="rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-950 dark:text-amber-100">
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle
                                            className="mt-0.5 size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        <div>
                                            <p className="font-semibold">
                                                {guidance.attention.title}
                                            </p>
                                            <p className="mt-0.5 leading-relaxed opacity-90">
                                                {guidance.attention.message}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {guidance?.showWhyBlocked ? <WhyBlockedHelp /> : null}
                    </>
                )}
            </div>
        </aside>
    );
}

function PanelHeader(): ReactElement {
    return (
        <div>
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Movement Guidance
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
                Phase-aware operational guidance for the selected crew member.
            </p>
        </div>
    );
}
