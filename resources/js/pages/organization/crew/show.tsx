import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ApplyTourOfDutyDialog } from '@/features/organization/crew/actions/apply-tour-of-duty-dialog';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import type { VesselTransferPrefill } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { VoidErroneousAssignmentDialog } from '@/features/organization/crew/actions/void-erroneous-assignment-dialog';
import { CrewAssignmentAccommodationCard } from '@/features/organization/crew/components/crew-assignment-accommodation-card';
import { CrewAssignmentIdentity } from '@/features/organization/crew/components/crew-assignment-identity';
import { CrewAssignmentOperationalSummary } from '@/features/organization/crew/components/crew-assignment-operational-summary';
import { CrewAssignmentOperationsCenter } from '@/features/organization/crew/components/crew-assignment-operations-center';
import { CrewAssignmentPhaseTimeline } from '@/features/organization/crew/components/crew-assignment-phase-timeline';
import {
    CrewAssignmentAuditRecord,
    CrewAssignmentRecordsSection,
    CrewAssignmentRemarksRecord,
} from '@/features/organization/crew/components/crew-assignment-records-section';
import { CrewAssignmentRelationships } from '@/features/organization/crew/components/crew-assignment-relationships';
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
import {
    edit as editAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import { cancel as cancelCorrection } from '@/routes/organization/crew-movement-corrections';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

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
        position_id: numberOrNull(params.get('position_id')),
        client_id: numberOrNull(params.get('client_id')),
        occurred_at: params.get('occurred_at'),
    };
}

function requestedCancelAssignment(
    canCancel: boolean,
    availableActions: string[],
): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get('action') !== 'cancel_assignment') {
        return false;
    }

    return canCancel && availableActions.includes('cancel_assignment');
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
                position_id: assignment.position?.id,
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
                position_id: assignment.position?.id ?? undefined,
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
            position_id: assignment.position?.id ?? undefined,
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
    const [correctionDialogMode, setCorrectionDialogMode] = useState<
        'request' | 'override'
    >('request');
    const [correctionInitialPhaseId, setCorrectionInitialPhaseId] = useState<
        number | null
    >(null);
    const [transferDismissed, setTransferDismissed] = useState(false);
    const [cancelDismissed, setCancelDismissed] = useState(false);
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

    const openCancelFromQuery = useMemo(
        () =>
            !cancelDismissed &&
            requestedCancelAssignment(can.cancel, assignment.available_actions),
        [assignment.available_actions, can.cancel, cancelDismissed],
    );

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

    const correctablePhases = useMemo(
        () =>
            corrections?.correctable_phases ??
            correction_request_context?.correctable_phases ??
            [],
        [
            corrections?.correctable_phases,
            correction_request_context?.correctable_phases,
        ],
    );

    const correctablePhaseIds = useMemo(
        () => new Set(correctablePhases.map((phase) => phase.id)),
        [correctablePhases],
    );

    const handleCancelPendingCorrection = (correctionId: number): void => {
        if (
            confirm(
                'Are you sure you want to cancel this pending correction request?',
            )
        ) {
            router.post(
                cancelCorrection.url(correctionId),
                {},
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const hasLinkedAssignments =
        Boolean(assignment.previous_assignment) ||
        (assignment.next_assignments?.length ?? 0) > 0;
    const hasRelationships =
        hasLinkedAssignments ||
        Boolean(assignment.relieves) ||
        Boolean(assignment.planning_assignment_id);
    const hasAccommodation =
        Boolean(assignment.accommodation) &&
        (assignment.accommodation?.length ?? 0) > 0;
    const hasRecordsContent =
        hasRelationships ||
        hasAccommodation ||
        (can.view_corrections && Boolean(corrections)) ||
        Boolean(assignment.remarks) ||
        can.view_audit;

    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                <CrewAssignmentIdentity assignment={assignment} can={can} />

                <CrewAssignmentOperationalSummary
                    assignment={assignment}
                    corrections={corrections}
                />

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                    <div className="order-2 min-w-0 space-y-6 xl:order-1">
                        <CrewAssignmentPhaseTimeline
                            assignment={assignment}
                            can={can}
                            correctablePhaseIds={correctablePhaseIds}
                            onCorrect={(phaseId) => {
                                setCorrectionDialogMode('override');
                                setCorrectionInitialPhaseId(phaseId);
                                setIsCorrectionDialogOpen(true);
                            }}
                            onCancelPending={handleCancelPendingCorrection}
                        />

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

                        {hasRecordsContent ? (
                            <CrewAssignmentRecordsSection>
                                <CrewAssignmentRelationships
                                    assignment={assignment}
                                    canViewPlanning={can.view_planning}
                                />
                                {hasAccommodation ? (
                                    <CrewAssignmentAccommodationCard
                                        items={assignment.accommodation ?? []}
                                    />
                                ) : null}
                                {can.view_corrections && corrections ? (
                                    <CorrectionHistoryCard
                                        corrections={corrections}
                                    />
                                ) : null}
                                {assignment.remarks ? (
                                    <CrewAssignmentRemarksRecord
                                        remarks={assignment.remarks}
                                    />
                                ) : null}
                                <CrewAssignmentAuditRecord
                                    items={recent_activity}
                                    canViewAudit={can.view_audit}
                                />
                            </CrewAssignmentRecordsSection>
                        ) : null}
                    </div>

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
                            onRequestCorrection={() => {
                                setCorrectionDialogMode('request');
                                setCorrectionInitialPhaseId(null);
                                setIsCorrectionDialogOpen(true);
                            }}
                            onOverrideCorrection={() => {
                                setCorrectionDialogMode('override');
                                setCorrectionInitialPhaseId(null);
                                setIsCorrectionDialogOpen(true);
                            }}
                            onVoid={() => setIsVoidDialogOpen(true)}
                            onEdit={() =>
                                router.visit(editAssignment.url(assignment.id))
                            }
                        />
                    </div>
                </div>
            </Main>

            {(can.request_correction || can.override_corrections) &&
            correctablePhases.length > 0 ? (
                <RequestCorrectionDialog
                    open={isCorrectionDialogOpen}
                    onOpenChange={setIsCorrectionDialogOpen}
                    assignmentId={assignment.id}
                    correctablePhases={correctablePhases}
                    formOptions={form_options}
                    companyTimezone={
                        assignment.movement_context?.company_timezone
                    }
                    allowFutureActualMovementDates={Boolean(
                        assignment.movement_context
                            ?.allow_future_actual_movement_dates,
                    )}
                    mode={correctionDialogMode}
                    initialPhaseId={correctionInitialPhaseId}
                />
            ) : null}

            <VoidErroneousAssignmentDialog
                open={isVoidDialogOpen}
                onOpenChange={setIsVoidDialogOpen}
                assignment={assignment}
                can={can}
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

            <MovementActionDialog
                open={openCancelFromQuery}
                onOpenChange={(open) => {
                    if (!open) {
                        setCancelDismissed(true);
                    }
                }}
                action={openCancelFromQuery ? 'cancel_assignment' : null}
                assignmentId={assignment.id}
                movementContext={assignment.movement_context}
                formOptions={form_options}
            />
        </>
    );
}
