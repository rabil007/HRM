import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock,
    FilePenLine,
    Pencil,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { ApplyTourOfDutyDialog } from '@/features/organization/crew/actions/apply-tour-of-duty-dialog';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import type { VesselTransferPrefill } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { VoidErroneousAssignmentDialog } from '@/features/organization/crew/actions/void-erroneous-assignment-dialog';
import { CrewAssignmentAccommodationCard } from '@/features/organization/crew/components/crew-assignment-accommodation-card';
import { CrewAssignmentOperationalSummary } from '@/features/organization/crew/components/crew-assignment-operational-summary';
import { CrewMetadataField } from '@/features/organization/crew/components/crew-metadata-field';
import { CrewMobilisationReadinessCard } from '@/features/organization/crew/components/crew-mobilisation-readiness-card';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewPhaseProgress } from '@/features/organization/crew/components/crew-phase-progress';
import { CrewRecommendedNextAction } from '@/features/organization/crew/components/crew-recommended-next-action';
import { CrewReliefReadinessBadge } from '@/features/organization/crew/components/crew-relief-readiness-badge';
import { CrewTourProgressDisplay } from '@/features/organization/crew/components/crew-tour-progress-display';
import { CorrectionHistoryCard } from '@/features/organization/crew/corrections/correction-history-card';
import { PendingCorrectionBanner } from '@/features/organization/crew/corrections/pending-correction-banner';
import { RequestCorrectionDialog } from '@/features/organization/crew/corrections/request-correction-dialog';
import { formatDaysInPhase } from '@/features/organization/crew/format-days-in-phase';
import type {
    CorrectionsSummary,
    CrewAssignmentDetail,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
    index as crewAssignmentsIndex,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { show as showEmployeeTraining } from '@/routes/organization/employees/training';

function requestedTransferPrefill(
    canPerformMovement: boolean,
    availableActions: string[],
): VesselTransferPrefill | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get('action') !== 'transfer_vessel') {
        return null;
    }

    if (!canPerformMovement || !availableActions.includes('transfer_vessel')) {
        return null;
    }

    const numberOrNull = (value: string | null): number | null => {
        if (!value) {
            return null;
        }

        const parsed = Number(value);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    };

    return {
        vessel_id: numberOrNull(params.get('vessel_id')),
        rank_id: numberOrNull(params.get('rank_id')),
        client_id: numberOrNull(params.get('client_id')),
        occurred_at: params.get('occurred_at'),
    };
}

function reliefActionHref(assignment: CrewAssignmentDetail): string {
    const status = assignment.relief_status;

    if (!status || status === 'no_relief') {
        return crewPlanningIndex.url({
            query: {
                vessel_id: assignment.vessel?.id,
                rank_id: assignment.rank?.id,
                relieves_crew_assignment_id: assignment.id,
                planned_join_date:
                    assignment.planned_signoff_at ??
                    assignment.source_planned_signoff_date ??
                    undefined,
                open_create: 1,
            },
        });
    }

    if (status === 'relief_planned') {
        return crewPlanningIndex.url({
            query: {
                vessel_id: assignment.vessel?.id ?? undefined,
                rank_id: assignment.rank?.id ?? undefined,
                search: assignment.relief_employee?.name ?? undefined,
            },
        });
    }

    if (assignment.relief_crew_assignment_id) {
        return showAssignment.url(assignment.relief_crew_assignment_id);
    }

    return crewPlanningIndex.url({
        query: {
            vessel_id: assignment.vessel?.id ?? undefined,
            rank_id: assignment.rank?.id ?? undefined,
            search: assignment.relief_employee?.name ?? undefined,
        },
    });
}

export default function CrewAssignmentShow({
    assignment,
    corrections,
    recent_activity,
    form_options,
    can,
}: {
    assignment: CrewAssignmentDetail;
    corrections?: CorrectionsSummary;
    recent_activity: RecentActivityItem[];
    form_options?: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [isCorrectionDialogOpen, setIsCorrectionDialogOpen] = useState(false);
    const [transferDismissed, setTransferDismissed] = useState(false);
    const [isVoidDialogOpen, setIsVoidDialogOpen] = useState(false);
    const [isApplyTourDialogOpen, setIsApplyTourDialogOpen] = useState(false);
    const requestedTransfer = useMemo(
        () =>
            requestedTransferPrefill(
                can.perform_movement,
                assignment.available_actions,
            ),
        [assignment.available_actions, can.perform_movement],
    );
    const transferPrefill = transferDismissed ? null : requestedTransfer;

    const showMovementActions =
        (can.perform_movement || can.cancel) &&
        assignment.available_actions.length > 0;
    const isOnVessel = assignment.current_phase?.code === 'p4';
    const reliefHref = isOnVessel ? reliefActionHref(assignment) : null;
    const reliefActionLabel =
        assignment.relief_action_label ??
        (assignment.relief_status === 'no_relief'
            ? 'Plan Relief'
            : assignment.relief_status === 'relief_planned'
              ? 'Open Relief Plan'
              : 'Open Relief Assignment');

    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                <DetailsHeader
                    kicker="Crew Assignments"
                    title={
                        assignment.employee ? (
                            <EmployeeProfileLink
                                employeeId={assignment.employee.id}
                            >
                                <span className="hover:underline">
                                    {assignment.employee.name}
                                </span>
                            </EmployeeProfileLink>
                        ) : (
                            assignment.assignment_no
                        )
                    }
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <span className="font-mono text-xs text-muted-foreground">
                                {assignment.assignment_no}
                            </span>
                            {assignment.employee?.employee_no ? (
                                <span className="font-mono text-xs text-muted-foreground">
                                    #{assignment.employee.employee_no}
                                </span>
                            ) : null}
                            <Badge
                                variant={
                                    assignment.status === 'active'
                                        ? 'success'
                                        : assignment.status === 'draft'
                                          ? 'secondary'
                                          : assignment.status === 'cancelled'
                                            ? 'destructive'
                                            : 'outline'
                                }
                            >
                                {assignment.status_label}
                            </Badge>
                            {assignment.current_phase ? (
                                <CrewPhaseBadge
                                    code={assignment.current_phase.code}
                                    label={assignment.current_phase.label}
                                    status={assignment.current_phase.status}
                                />
                            ) : null}
                            {assignment.days_in_phase !== null ? (
                                <span className="text-muted-foreground">
                                    {formatDaysInPhase(
                                        assignment.days_in_phase,
                                    )}
                                </span>
                            ) : null}
                        </span>
                    }
                    backHref={crewAssignmentsIndex.url()}
                    backLabel="Back to Crew Assignments"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {can.perform_movement &&
                            assignment.can_apply_tour_of_duty ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 rounded-lg px-4"
                                    onClick={() =>
                                        setIsApplyTourDialogOpen(true)
                                    }
                                >
                                    <Clock className="mr-2 h-4 w-4" />
                                    Apply Tour of Duty
                                </Button>
                            ) : null}
                            {can.void ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="h-10 rounded-lg px-4"
                                    onClick={() => setIsVoidDialogOpen(true)}
                                >
                                    Void Assignment
                                </Button>
                            ) : null}
                            {can.update && assignment.is_editable ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 rounded-lg px-4"
                                    onClick={() =>
                                        router.visit(
                                            editAssignment.url(assignment.id),
                                        )
                                    }
                                >
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            ) : null}
                            {can.request_correction &&
                            (corrections?.correctable_phases.length ?? 0) >
                                0 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 rounded-lg px-4"
                                    onClick={() =>
                                        setIsCorrectionDialogOpen(true)
                                    }
                                >
                                    <FilePenLine className="mr-2 h-4 w-4" />
                                    Request Correction
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {/* Two-column layout: left = operational, right = employee & details */}
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    {/* ── LEFT COLUMN: Operational content ── */}
                    <div className="min-w-0 space-y-6">
                        <div className="space-y-4">
                            <CrewAssignmentOperationalSummary
                                assignment={assignment}
                                corrections={corrections}
                            />

                            {assignment.accommodation &&
                            assignment.accommodation.length > 0 ? (
                                <CrewAssignmentAccommodationCard
                                    items={assignment.accommodation}
                                />
                            ) : null}

                            {corrections ? (
                                <PendingCorrectionBanner
                                    corrections={corrections}
                                />
                            ) : null}

                            {assignment.warnings.length > 0 ? (
                                <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                                    <div className="mb-3 flex items-center gap-2 text-amber-700 dark:text-amber-300">
                                        <AlertTriangle
                                            className="size-4"
                                            aria-hidden
                                        />
                                        <h2 className="text-sm font-semibold">
                                            Needs attention
                                        </h2>
                                    </div>
                                    <div className="grid gap-2 md:grid-cols-2">
                                        {assignment.warnings.map(
                                            (warning, idx) => (
                                                <div
                                                    key={`${warning.code}-${idx}`}
                                                    className="rounded-lg border border-amber-500/20 bg-background/60 p-3"
                                                >
                                                    <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                                        {warning.label}
                                                    </p>
                                                    <p className="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">
                                                        {warning.message}
                                                    </p>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            ) : null}

                            {showMovementActions ||
                            assignment.recommended_action ? (
                                <CrewRecommendedNextAction
                                    assignmentId={assignment.id}
                                    recommended={assignment.recommended_action}
                                    availableActions={
                                        assignment.available_actions
                                    }
                                    movementContext={
                                        assignment.movement_context
                                    }
                                    formOptions={form_options}
                                    canViewDocuments={can.view_documents}
                                    canViewPlanning={can.view_planning}
                                    canPerformMovement={can.perform_movement}
                                    canCancel={can.cancel}
                                    currentPhase={assignment.current_phase}
                                    status={assignment.status}
                                    statusLabel={assignment.status_label}
                                    vesselName={assignment.vessel?.name ?? null}
                                />
                            ) : null}
                        </div>

                        {/* ─ Timeline & On-Vessel ─ */}
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/60 uppercase">
                                    Timeline
                                </span>
                                <Separator className="flex-1 opacity-50" />
                            </div>

                            {isOnVessel ? (
                                <Card className="border-border/80 dark:border-white/10">
                                    <CardHeader className="flex flex-row items-start justify-between gap-3 pb-3">
                                        <CardTitle className="text-base">
                                            Tour of Duty
                                        </CardTitle>
                                        {can.perform_movement &&
                                        assignment.can_apply_tour_of_duty ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-8 rounded-lg"
                                                onClick={() =>
                                                    setIsApplyTourDialogOpen(
                                                        true,
                                                    )
                                                }
                                            >
                                                <Clock className="mr-1.5 h-3.5 w-3.5" />
                                                Apply Tour of Duty
                                            </Button>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent>
                                        <CrewTourProgressDisplay
                                            progress={assignment}
                                            plannedSignoffAt={
                                                assignment.planned_signoff_at
                                            }
                                        />
                                    </CardContent>
                                </Card>
                            ) : null}

                            {isOnVessel ? (
                                <Card className="border-border/80 dark:border-white/10">
                                    <CardHeader className="flex flex-row items-start justify-between gap-3 border-b border-border/50 pb-3 dark:border-white/5">
                                        <CardTitle className="text-base">
                                            Relief Readiness
                                        </CardTitle>
                                        {reliefHref ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                                className="h-8 rounded-lg"
                                            >
                                                <Link href={reliefHref}>
                                                    {reliefActionLabel}
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent className="space-y-4 pt-0">
                                        <CrewReliefReadinessBadge
                                            relief_status={
                                                assignment.relief_status
                                            }
                                            relief_status_label={
                                                assignment.relief_status_label
                                            }
                                            relief_risk={assignment.relief_risk}
                                            relief_risk_label={
                                                assignment.relief_risk_label
                                            }
                                            relief_employee={
                                                assignment.relief_employee
                                            }
                                        />
                                        <CrewMetadataField
                                            label="Planned join"
                                            value={formatDisplayDate(
                                                assignment.relief_planned_join_date,
                                            )}
                                        />
                                        <CrewMetadataField
                                            label="Days until sign-off"
                                            value={
                                                assignment.days_until_signoff !==
                                                null
                                                    ? String(
                                                          assignment.days_until_signoff,
                                                      )
                                                    : '—'
                                            }
                                        />
                                        {assignment.relief_phase_code ? (
                                            <CrewMetadataField
                                                label="Relief phase"
                                                value={
                                                    <CrewPhaseBadge
                                                        code={
                                                            assignment.relief_phase_code
                                                        }
                                                        label={
                                                            assignment.relief_phase_label ??
                                                            assignment.relief_phase_code
                                                        }
                                                        status={
                                                            assignment.relief_phase_status ??
                                                            undefined
                                                        }
                                                    />
                                                }
                                            />
                                        ) : null}
                                        {assignment.relief_crew_assignment_id ? (
                                            <CrewMetadataField
                                                label="Relief assignment"
                                                value={
                                                    <Link
                                                        href={showAssignment.url(
                                                            assignment.relief_crew_assignment_id,
                                                        )}
                                                        className="text-primary hover:underline"
                                                    >
                                                        Open assignment
                                                    </Link>
                                                }
                                            />
                                        ) : null}
                                    </CardContent>
                                </Card>
                            ) : null}

                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Phase Timeline
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {assignment.phase_timeline.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No phase timeline recorded yet.
                                        </p>
                                    ) : (
                                        <ol className="relative space-y-0 border-l border-border/70 pl-5">
                                            {assignment.phase_timeline.map(
                                                (phase, index) => {
                                                    const isCurrent =
                                                        assignment.current_phase
                                                            ?.id === phase.id;

                                                    return (
                                                        <li
                                                            key={phase.id}
                                                            className="relative pb-6 last:pb-0"
                                                        >
                                                            <span
                                                                className={cn(
                                                                    'absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full border-2 border-background',
                                                                    isCurrent
                                                                        ? 'bg-primary'
                                                                        : phase.status ===
                                                                            'completed'
                                                                          ? 'bg-emerald-500'
                                                                          : 'bg-muted-foreground/40',
                                                                )}
                                                                aria-hidden
                                                            />
                                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                                <div className="space-y-1">
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <CrewPhaseBadge
                                                                            code={
                                                                                phase.phase_code
                                                                            }
                                                                            label={
                                                                                phase.phase_label
                                                                            }
                                                                            status={
                                                                                phase.status
                                                                            }
                                                                        />
                                                                        {isCurrent ? (
                                                                            <Badge variant="outline">
                                                                                Current
                                                                            </Badge>
                                                                        ) : null}
                                                                        {phase.has_pending_correction ? (
                                                                            <Badge variant="warning">
                                                                                Pending
                                                                                Correction
                                                                            </Badge>
                                                                        ) : phase.has_approved_correction ? (
                                                                            <Badge variant="secondary">
                                                                                Corrected
                                                                            </Badge>
                                                                        ) : null}
                                                                        {phase.phase_code ===
                                                                            'p2b' &&
                                                                        phase.employee_training_id ? (
                                                                            can.view_training &&
                                                                            assignment
                                                                                .employee
                                                                                ?.id ? (
                                                                                <Link
                                                                                    href={showEmployeeTraining.url(
                                                                                        {
                                                                                            employee:
                                                                                                assignment
                                                                                                    .employee
                                                                                                    .id,
                                                                                            training:
                                                                                                phase.employee_training_id,
                                                                                        },
                                                                                    )}
                                                                                    className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300"
                                                                                >
                                                                                    <CheckCircle2 className="size-3 text-emerald-600 dark:text-emerald-400" />
                                                                                    Added
                                                                                    to
                                                                                    Employee
                                                                                    Training
                                                                                </Link>
                                                                            ) : (
                                                                                <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                                                    <CheckCircle2 className="size-3 text-emerald-600 dark:text-emerald-400" />
                                                                                    Added
                                                                                    to
                                                                                    Employee
                                                                                    Training
                                                                                </span>
                                                                            )
                                                                        ) : null}
                                                                    </div>
                                                                    <p className="text-sm text-muted-foreground">
                                                                        {
                                                                            phase.status_label
                                                                        }
                                                                        {index ===
                                                                        0
                                                                            ? ' · Sequence start'
                                                                            : ''}
                                                                        {phase.phase_code ===
                                                                            'p2b' &&
                                                                        phase
                                                                            .details
                                                                            ?.course
                                                                            ? ` · ${String(phase.details.course)}`
                                                                            : ''}
                                                                    </p>
                                                                </div>
                                                                <div className="text-right text-xs text-muted-foreground">
                                                                    <div>
                                                                        Actual:{' '}
                                                                        {formatDisplayDate(
                                                                            phase.actual_start_at,
                                                                        )}
                                                                        {phase.actual_end_at
                                                                            ? ` → ${formatDisplayDate(phase.actual_end_at)}`
                                                                            : ''}
                                                                    </div>
                                                                    {(phase.planned_start_at ||
                                                                        phase.planned_end_at) && (
                                                                        <div className="mt-1">
                                                                            Planned:{' '}
                                                                            {formatDisplayDate(
                                                                                phase.planned_start_at,
                                                                            )}
                                                                            {phase.planned_end_at
                                                                                ? ` → ${formatDisplayDate(phase.planned_end_at)}`
                                                                                : ''}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </li>
                                                    );
                                                },
                                            )}
                                        </ol>
                                    )}
                                </CardContent>
                            </Card>

                            {can.view_corrections && corrections ? (
                                <CorrectionHistoryCard
                                    corrections={corrections}
                                />
                            ) : null}

                            {(can.perform_movement || can.cancel) &&
                            assignment.available_actions.length === 0 &&
                            assignment.status !== 'completed' &&
                            assignment.status !== 'cancelled' ? (
                                <Card className="border-border/80 dark:border-white/10">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">
                                            Movement Actions
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm text-muted-foreground">
                                            No available actions for the current
                                            phase.
                                        </p>
                                    </CardContent>
                                </Card>
                            ) : null}

                            {can.view_audit ? (
                                recent_activity.length > 0 ? (
                                    <RecentActivityCard
                                        items={recent_activity}
                                        description="Latest changes for this crew assignment."
                                    />
                                ) : (
                                    <Card className="border-border/80 dark:border-white/10">
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-base">
                                                Audit History
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-sm text-muted-foreground">
                                                No audit history recorded for
                                                this assignment yet.
                                            </p>
                                        </CardContent>
                                    </Card>
                                )
                            ) : null}
                        </div>
                        {/* end Timeline section */}
                    </div>

                    {/* ── RIGHT COLUMN: Context & verification (sticky sidebar) ── */}
                    <div className="space-y-4 xl:sticky xl:top-4 xl:self-start">
                        {/* Mobilisation Checks */}
                        {assignment.mobilisation_readiness ? (
                            <CrewMobilisationReadinessCard
                                readiness={assignment.mobilisation_readiness}
                                canViewDocuments={can.view_documents}
                            />
                        ) : null}

                        {/* Plan vs Actual dates — comparison grid */}
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                                <div className="flex items-center gap-2">
                                    <CalendarDays className="size-3.5 text-muted-foreground" />
                                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Plan vs Actual
                                    </CardTitle>
                                </div>
                                {/* Column headers */}
                                <div className="mt-2 grid grid-cols-[1fr_auto_auto] gap-x-3 px-1">
                                    <span />
                                    <span className="text-[10px] font-bold tracking-wide text-muted-foreground/60 uppercase">
                                        Planned
                                    </span>
                                    <span className="text-[10px] font-bold tracking-wide text-primary/70 uppercase">
                                        Actual
                                    </span>
                                </div>
                            </CardHeader>
                            <CardContent className="px-2 pt-2 pb-1">
                                {(
                                    [
                                        {
                                            label: 'Arrival',
                                            planned:
                                                assignment.planned_arrival_at,
                                            actual: assignment.actual_arrival_at,
                                        },
                                        {
                                            label: 'Vessel Join',
                                            planned: assignment.planned_join_at,
                                            actual: assignment.actual_join_at,
                                        },
                                        {
                                            label: 'Travel',
                                            planned:
                                                assignment.planned_travel_at,
                                            actual: null,
                                        },
                                        {
                                            label: 'Sign-Off',
                                            planned:
                                                assignment.planned_signoff_at,
                                            actual: assignment.actual_disembarkation_at,
                                        },
                                        {
                                            label: 'Started',
                                            planned: null,
                                            actual: assignment.started_at,
                                        },
                                        {
                                            label: 'Closed',
                                            planned: null,
                                            actual: assignment.closed_at,
                                        },
                                    ] as {
                                        label: string;
                                        planned: string | null;
                                        actual: string | null;
                                    }[]
                                ).map((row) => {
                                    const hasVariance =
                                        row.planned &&
                                        row.actual &&
                                        row.planned !== row.actual;

                                    return (
                                        <div
                                            key={row.label}
                                            className="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 border-b border-border/30 px-1 py-2 last:border-b-0"
                                        >
                                            <span className="text-[11px] font-medium text-muted-foreground">
                                                {row.label}
                                            </span>
                                            <span className="text-right text-[11px] text-muted-foreground/70">
                                                {formatDisplayDate(
                                                    row.planned,
                                                ) ?? '—'}
                                            </span>
                                            <span
                                                className={cn(
                                                    'text-right text-[11px] font-medium',
                                                    hasVariance
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : row.actual
                                                          ? 'text-foreground'
                                                          : 'text-muted-foreground/40',
                                                )}
                                            >
                                                {formatDisplayDate(
                                                    row.actual,
                                                ) ?? '—'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Assignment journey */}
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                                <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Assignment Journey
                                </CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    P0–P6 operating lifecycle
                                </p>
                            </CardHeader>
                            <CardContent className="pt-4">
                                <CrewPhaseProgress
                                    currentPhaseCode={
                                        assignment.current_phase?.code ?? null
                                    }
                                    phaseTimeline={assignment.phase_timeline}
                                />
                            </CardContent>
                        </Card>

                        {/* Linked assignments */}
                        {assignment.previous_assignment ||
                        (assignment.next_assignments?.length ?? 0) > 0 ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Linked Assignments
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 pt-3">
                                    {assignment.previous_assignment ? (
                                        <Link
                                            href={showAssignment.url(
                                                assignment.previous_assignment
                                                    .id,
                                            )}
                                            className="group flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-muted/30 px-3 py-2.5 transition-colors hover:border-border hover:bg-muted/60"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-[10px] font-bold tracking-wide text-muted-foreground/60 uppercase">
                                                    Previous
                                                </p>
                                                <p className="mt-0.5 truncate text-sm font-medium text-foreground">
                                                    {
                                                        assignment
                                                            .previous_assignment
                                                            .assignment_no
                                                    }
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {
                                                        assignment
                                                            .previous_assignment
                                                            .status_label
                                                    }
                                                    {assignment
                                                        .previous_assignment
                                                        .vessel_name
                                                        ? ` · ${
                                                              assignment
                                                                  .previous_assignment
                                                                  .vessel_name
                                                          }`
                                                        : ''}
                                                </p>
                                            </div>
                                            <ChevronRight className="size-4 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5" />
                                        </Link>
                                    ) : null}
                                    {(assignment.next_assignments ?? []).map(
                                        (next) => (
                                            <Link
                                                key={next.id}
                                                href={showAssignment.url(
                                                    next.id,
                                                )}
                                                className="group flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/3 px-3 py-2.5 transition-colors hover:border-primary/40 hover:bg-primary/8"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-[10px] font-bold tracking-wide text-primary/60 uppercase">
                                                        Next
                                                    </p>
                                                    <p className="mt-0.5 truncate text-sm font-medium text-foreground">
                                                        {next.assignment_no}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {next.status_label}
                                                        {next.vessel_name
                                                            ? ` · ${next.vessel_name}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <ChevronRight className="size-4 shrink-0 text-primary/40 transition-transform group-hover:translate-x-0.5" />
                                            </Link>
                                        ),
                                    )}
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* Relieves */}
                        {assignment.relieves ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Relieves
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-0 divide-y divide-border/40 pt-4">
                                    <CrewMetadataField
                                        label="Source assignment"
                                        value={
                                            <Link
                                                href={showAssignment.url(
                                                    assignment.relieves
                                                        .source_assignment_id,
                                                )}
                                                className="text-primary hover:underline"
                                            >
                                                {
                                                    assignment.relieves
                                                        .source_assignment_no
                                                }
                                            </Link>
                                        }
                                    />
                                    <CrewMetadataField
                                        label="Employee"
                                        value={
                                            assignment.relieves.source_employee
                                                ? assignment.relieves
                                                      .source_employee.name
                                                : '—'
                                        }
                                    />
                                    <CrewMetadataField
                                        label="Vessel"
                                        value={
                                            assignment.relieves.source_vessel
                                                ?.name ?? '—'
                                        }
                                    />
                                    <CrewMetadataField
                                        label="Rank"
                                        value={
                                            assignment.relieves.source_rank
                                                ?.name ?? '—'
                                        }
                                    />
                                    <CrewMetadataField
                                        label="Planned sign-off"
                                        value={formatDisplayDate(
                                            assignment.relieves
                                                .source_planned_signoff_at,
                                        )}
                                    />
                                    <div className="pt-2">
                                        <Button
                                            asChild
                                            variant="link"
                                            className="h-auto px-0"
                                        >
                                            <Link
                                                href={crewPlanningIndex.url({
                                                    query: {
                                                        vessel_id:
                                                            assignment.vessel
                                                                ?.id ??
                                                            undefined,
                                                        rank_id:
                                                            assignment.rank
                                                                ?.id ??
                                                            undefined,
                                                        search:
                                                            assignment.employee
                                                                ?.name ??
                                                            undefined,
                                                    },
                                                })}
                                            >
                                                Open Crew Planning
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* Remarks */}
                        {assignment.remarks ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Remarks
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-4">
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {assignment.remarks}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* Planning link */}
                        {assignment.planning_assignment_id ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                        Planning Link
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        Created from crew planning assignment #
                                        {assignment.planning_assignment_id}.
                                    </p>
                                    <Button
                                        asChild
                                        variant="link"
                                        className="mt-1 h-auto px-0"
                                    >
                                        <Link href={crewPlanningIndex.url()}>
                                            Open Crew Planning
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </Main>

            {corrections ? (
                <RequestCorrectionDialog
                    open={isCorrectionDialogOpen}
                    onOpenChange={setIsCorrectionDialogOpen}
                    assignmentId={assignment.id}
                    correctablePhases={corrections.correctable_phases}
                    formOptions={form_options}
                    companyTimezone={
                        assignment.movement_context?.company_timezone
                    }
                />
            ) : null}

            <VoidErroneousAssignmentDialog
                open={isVoidDialogOpen}
                onOpenChange={setIsVoidDialogOpen}
                assignment={assignment}
            />

            <ApplyTourOfDutyDialog
                open={isApplyTourDialogOpen}
                onOpenChange={setIsApplyTourDialogOpen}
                assignment={assignment}
            />

            <MovementActionDialog
                open={transferPrefill !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTransferDismissed(true);
                    }
                }}
                action={transferPrefill ? 'transfer_vessel' : null}
                assignmentId={assignment.id}
                movementContext={assignment.movement_context}
                formOptions={form_options}
                transferPrefill={transferPrefill}
            />
        </>
    );
}
