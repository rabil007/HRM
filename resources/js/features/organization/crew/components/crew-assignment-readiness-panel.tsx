import { AlertTriangle, ArrowRight, CheckCircle2, Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { CrewEmployeeStatusBadge } from '@/features/organization/crew/components/crew-employee-status-badge';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import {
    buildAssignmentReadinessAttentionItems,
    buildAssignmentReadinessRecommendation,
    isHomeAvailabilityStatus,
    shouldShowTransferVesselSuggestion,
} from '@/features/organization/crew/lib/assignment-readiness';
import type {
    ActiveOnVesselAssignment,
    CrewAssignmentCreateFormOptions,
    EmployeeOperationalStatus,
} from '@/features/organization/crew/types';
import {
    formatDisplayDate,
    formatDisplayDateTimeInTimezone,
} from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { Button } from '@/components/ui/button';

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
        <div className="rounded-lg border border-dashed border-border/70 bg-muted/10 px-4 py-5 text-sm text-muted-foreground">
            Select an employee to view their current crew status, assignment,
            availability, and important operational information.
        </div>
    );
}

export function CrewAssignmentReadinessPanel({
    employeeId,
    formOptions,
    destinationVesselId = null,
    planningEmployeeName = null,
    planningRankName = null,
    bulkMode = false,
    className,
}: {
    employeeId: number | null;
    formOptions: CrewAssignmentCreateFormOptions;
    destinationVesselId?: number | null;
    planningEmployeeName?: string | null;
    planningRankName?: string | null;
    bulkMode?: boolean;
    className?: string;
}): ReactElement {
    if (bulkMode) {
        return (
            <aside
                className={cn(
                    'rounded-xl border glass-card p-5',
                    className,
                )}
                aria-label="Assignment Readiness"
            >
                <PanelHeader />
                <div className="mt-4 rounded-lg border border-dashed border-border/70 bg-muted/10 px-4 py-5 text-sm text-muted-foreground">
                    Assignment Readiness is available when starting one crew
                    member at a time. Review each bulk row status in the table
                    below.
                </div>
            </aside>
        );
    }

    const employee =
        employeeId != null
            ? formOptions.employees.find((item) => item.id === employeeId)
            : null;
    const status: EmployeeOperationalStatus | null =
        employeeId != null
            ? (formOptions.employee_status_by_employee?.[String(employeeId)] ??
              null)
            : null;
    const activeOnVessel: ActiveOnVesselAssignment | null =
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

    const attentionItems = buildAssignmentReadinessAttentionItems(
        status,
        activeOnVessel,
        destinationVesselId,
    );
    const recommendation = buildAssignmentReadinessRecommendation(
        status,
        activeOnVessel,
        destinationVesselId,
    );
    const showTransferSuggestion = shouldShowTransferVesselSuggestion(
        status,
        activeOnVessel,
        destinationVesselId,
    );

    const sinceRaw = activeOnVessel?.actual_start_at ?? status?.since ?? null;
    const sinceText = sinceRaw
        ? formatDisplayDateTimeInTimezone(sinceRaw, companyTimezone)
        : null;
    const vesselName =
        status?.vessel_name ??
        status?.current_vessel ??
        activeOnVessel?.vessel_name ??
        null;

    return (
        <aside
            className={cn('rounded-xl border glass-card p-5', className)}
            aria-label="Assignment Readiness"
            aria-live="polite"
        >
            <PanelHeader />

            <div className="mt-4 space-y-5">
                {!employee && !planningEmployeeName ? (
                    <ReadinessEmptyState />
                ) : (
                    <>
                        <div className="flex items-start gap-3">
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
                                    {employee?.name ??
                                        planningEmployeeName ??
                                        'Selected employee'}
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

                        {status?.current_phase && status.label ? (
                            <ReadinessSection title="Current crew status">
                                <CrewPhaseBadge
                                    code={status.current_phase}
                                    label={status.label}
                                    status="active"
                                />
                            </ReadinessSection>
                        ) : null}

                        {status?.assignment_no ||
                        status?.has_active_assignment ||
                        vesselName ? (
                            <ReadinessSection title="Current assignment">
                                <div className="space-y-1 rounded-lg border border-border/60 bg-muted/15 px-3 py-2.5 text-sm">
                                    {status?.assignment_no ? (
                                        <p className="font-mono text-xs font-semibold">
                                            {status.assignment_no}
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
                                            Since: {sinceText}
                                        </p>
                                    ) : null}
                                    {status?.days_in_phase != null ? (
                                        <p className="text-xs text-muted-foreground">
                                            {status.days_in_phase}{' '}
                                            {status.days_in_phase === 1
                                                ? 'day'
                                                : 'days'}
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
                                    {status?.assignment_id &&
                                    status.assignment_no ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="mt-2 h-7 gap-1.5 rounded-lg px-2.5 text-xs"
                                            onClick={() =>
                                                window.open(
                                                    showAssignment.url(
                                                        status.assignment_id as number,
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
                        ) ? (
                            <ReadinessSection title="Home duration">
                                <div className="space-y-1 rounded-lg border border-border/60 bg-muted/15 px-3 py-2.5 text-sm">
                                    <p className="font-medium">
                                        Home for:{' '}
                                        {status?.days_at_home ??
                                            status?.in_home_days ??
                                            '—'}{' '}
                                        days
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Availability limit:{' '}
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

                        {attentionItems.length > 0 ? (
                            <ReadinessSection title="Attention">
                                <div className="space-y-2">
                                    {attentionItems.map((item) => (
                                        <div
                                            key={item.id}
                                            className={cn(
                                                'rounded-lg border px-3 py-2.5 text-xs',
                                                item.tone === 'warning'
                                                    ? 'border-amber-500/35 bg-amber-500/10 text-amber-950 dark:text-amber-100'
                                                    : 'border-sky-500/30 bg-sky-500/10 text-sky-950 dark:text-sky-100',
                                            )}
                                        >
                                            <div className="flex items-start gap-2">
                                                {item.tone === 'warning' ? (
                                                    <AlertTriangle
                                                        className="mt-0.5 size-3.5 shrink-0"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <Info
                                                        className="mt-0.5 size-3.5 shrink-0"
                                                        aria-hidden
                                                    />
                                                )}
                                                <div>
                                                    <p className="font-semibold">
                                                        {item.title}
                                                    </p>
                                                    <p className="mt-0.5 leading-relaxed opacity-90">
                                                        {item.description}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {showTransferSuggestion ? (
                            <ReadinessSection title="Transfer Vessel">
                                <div className="rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-950 dark:text-amber-100">
                                    <p className="font-semibold">
                                        Already On Vessel
                                    </p>
                                    <p className="mt-1 leading-relaxed">
                                        If the employee is moving directly to
                                        another vessel, consider using Transfer
                                        Vessel instead of creating an
                                        overlapping assignment.
                                    </p>
                                </div>
                            </ReadinessSection>
                        ) : null}

                        {recommendation ? (
                            <ReadinessSection title="Recommended action">
                                <div
                                    className={cn(
                                        'rounded-lg border px-3 py-2.5 text-xs',
                                        recommendation.tone === 'positive'
                                            ? 'border-emerald-500/35 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100'
                                            : recommendation.tone === 'warning'
                                              ? 'border-amber-500/35 bg-amber-500/10 text-amber-950 dark:text-amber-100'
                                              : 'border-border/60 bg-muted/15 text-foreground',
                                    )}
                                >
                                    <div className="flex items-start gap-2">
                                        {recommendation.tone ===
                                        'positive' ? (
                                            <CheckCircle2
                                                className="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden
                                            />
                                        ) : (
                                            <Info
                                                className="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden
                                            />
                                        )}
                                        <div>
                                            <p className="font-semibold">
                                                {recommendation.title}
                                            </p>
                                            <p className="mt-0.5 leading-relaxed opacity-90">
                                                {recommendation.description}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </ReadinessSection>
                        ) : null}
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
                Assignment Readiness
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Operational context for the selected crew member.
            </p>
        </div>
    );
}
