import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ApplyTourOfDutyDialog } from '@/features/organization/crew/actions/apply-tour-of-duty-dialog';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import type { VesselTransferPrefill } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { VoidErroneousAssignmentDialog } from '@/features/organization/crew/actions/void-erroneous-assignment-dialog';
import { CrewAssignmentAccommodationCard } from '@/features/organization/crew/components/crew-assignment-accommodation-card';
import { CrewAssignmentIdentity } from '@/features/organization/crew/components/crew-assignment-identity';
import { CrewAssignmentOperationalSummary } from '@/features/organization/crew/components/crew-assignment-operational-summary';
import { CrewAssignmentOperationsCenter } from '@/features/organization/crew/components/crew-assignment-operations-center';
import { CrewAssignmentPlanVsActual } from '@/features/organization/crew/components/crew-assignment-plan-vs-actual';
import { CrewAssignmentRelationships } from '@/features/organization/crew/components/crew-assignment-relationships';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewPhaseProgress } from '@/features/organization/crew/components/crew-phase-progress';
import { CrewTourProgressDisplay } from '@/features/organization/crew/components/crew-tour-progress-display';
import { CorrectionHistoryCard } from '@/features/organization/crew/corrections/correction-history-card';
import { RequestCorrectionDialog } from '@/features/organization/crew/corrections/request-correction-dialog';
import type {
    CorrectionsSummary,
    CrewAssignmentDetail,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
    CrewCorrectionRequestContext,
} from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
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

function reliefActionHref(
    assignment: CrewAssignmentDetail,
    canViewPlanning: boolean,
    canViewAssignments: boolean,
): string | null {
    const status = assignment.relief_status;

    if (!status || status === 'no_relief') {
        if (!canViewPlanning) {
            return null;
        }

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
        if (!canViewPlanning) {
            return null;
        }

        return crewPlanningIndex.url({
            query: {
                vessel_id: assignment.vessel?.id ?? undefined,
                rank_id: assignment.rank?.id ?? undefined,
                search: assignment.relief_employee?.name ?? undefined,
            },
        });
    }

    if (assignment.relief_crew_assignment_id) {
        if (!canViewAssignments) {
            return null;
        }

        return showAssignment.url(assignment.relief_crew_assignment_id);
    }

    if (!canViewPlanning) {
        return null;
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
    correction_request_context,
    recent_activity,
    form_options,
    can,
}: {
    assignment: CrewAssignmentDetail;
    corrections?: CorrectionsSummary | null;
    correction_request_context?: CrewCorrectionRequestContext | null;
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

    const isOnVessel = assignment.current_phase?.code === 'p4';
    const reliefHref = isOnVessel
        ? reliefActionHref(assignment, can.view_planning, can.view)
        : null;
    const reliefActionLabel =
        assignment.relief_action_label ??
        (assignment.relief_status === 'no_relief'
            ? 'Plan Relief'
            : assignment.relief_status === 'relief_planned'
              ? 'Open Relief Plan'
              : 'Open Relief Assignment');

    const correctablePhases =
        corrections?.correctable_phases ??
        correction_request_context?.correctable_phases ??
        [];

    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                {/* ── TOP: Employee / Assignment Identity Section ── */}
                <CrewAssignmentIdentity
                    assignment={assignment}
                    can={can}
                    onEdit={() =>
                        router.visit(editAssignment.url(assignment.id))
                    }
                />

                {/* ── TOP: Operational Summary ── */}
                <CrewAssignmentOperationalSummary
                    assignment={assignment}
                    corrections={corrections}
                />

                {/* ── MAIN CONTENT: Two-column layout ── */}
                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                    {/* ── LEFT COLUMN: Assignment Record / History / Analytics ── */}
                    <div className="order-2 min-w-0 space-y-6 xl:order-1">
                        {/* A. Assignment Journey */}
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

                        {/* B. Plan vs Actual */}
                        <CrewAssignmentPlanVsActual assignment={assignment} />

                        {/* C. Tour of Duty (analytical, on vessel) */}
                        {isOnVessel ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Tour of Duty
                                    </CardTitle>
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

                        {/* D. Accommodation */}
                        {assignment.accommodation &&
                        assignment.accommodation.length > 0 ? (
                            <CrewAssignmentAccommodationCard
                                items={assignment.accommodation}
                            />
                        ) : null}

                        {/* E. Phase Timeline */}
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
                                                                    {(can.view_corrections ||
                                                                        can.request_correction) &&
                                                                    phase.has_pending_correction ? (
                                                                        <Badge variant="warning">
                                                                            Pending
                                                                            Correction
                                                                        </Badge>
                                                                    ) : can.view_corrections &&
                                                                      phase.has_approved_correction ? (
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
                                                                    {index === 0
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

                        {/* F. Assignment Relationships */}
                        <CrewAssignmentRelationships
                            assignment={assignment}
                            canViewPlanning={can.view_planning}
                        />

                        {/* G. Correction History */}
                        {can.view_corrections && corrections ? (
                            <CorrectionHistoryCard corrections={corrections} />
                        ) : null}

                        {/* H. Remarks */}
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

                        {/* I. Audit History */}
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
                                            No audit history recorded for this
                                            assignment yet.
                                        </p>
                                    </CardContent>
                                </Card>
                            )
                        ) : null}
                    </div>

                    {/* ── RIGHT COLUMN: Operations Center (sticky sidebar on desktop) ── */}
                    <div className="order-1 min-w-0 [scrollbar-width:thin] xl:sticky xl:top-4 xl:order-2 xl:max-h-[calc(100vh-2rem)] xl:self-start xl:overflow-y-auto xl:pr-1.5">
                        <CrewAssignmentOperationsCenter
                            assignment={assignment}
                            corrections={corrections}
                            correctionRequestContext={
                                correction_request_context
                            }
                            can={can}
                            formOptions={form_options}
                            reliefHref={reliefHref}
                            reliefActionLabel={reliefActionLabel}
                            onApplyTour={() => setIsApplyTourDialogOpen(true)}
                            onRequestCorrection={() =>
                                setIsCorrectionDialogOpen(true)
                            }
                            onVoid={() => setIsVoidDialogOpen(true)}
                            onEdit={() =>
                                router.visit(editAssignment.url(assignment.id))
                            }
                        />
                    </div>
                </div>
            </Main>

            {can.request_correction && correctablePhases.length > 0 ? (
                <RequestCorrectionDialog
                    open={isCorrectionDialogOpen}
                    onOpenChange={setIsCorrectionDialogOpen}
                    assignmentId={assignment.id}
                    correctablePhases={correctablePhases}
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
