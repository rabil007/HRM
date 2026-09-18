import { Clock, FilePenLine, Pencil, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CrewAssignmentAttentionCard } from '@/features/organization/crew/components/crew-assignment-attention-card';
import { CrewAssignmentReliefCard } from '@/features/organization/crew/components/crew-assignment-relief-card';
import { CrewMobilisationReadinessCard } from '@/features/organization/crew/components/crew-mobilisation-readiness-card';
import { CrewRecommendedNextAction } from '@/features/organization/crew/components/crew-recommended-next-action';
import type {
    CorrectionsSummary,
    CrewAssignmentDetail,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
    CrewCorrectionRequestContext,
} from '@/features/organization/crew/types';

export function CrewAssignmentOperationsCenter({
    assignment,
    corrections,
    correctionRequestContext,
    can,
    formOptions,
    reliefHref,
    reliefActionLabel,
    onApplyTour,
    onRequestCorrection,
    onVoid,
    onEdit,
}: {
    assignment: CrewAssignmentDetail;
    corrections?: CorrectionsSummary | null;
    correctionRequestContext?: CrewCorrectionRequestContext | null;
    can: CrewAssignmentPagePermissions;
    formOptions?: CrewAssignmentFormOptions;
    reliefHref: string | null;
    reliefActionLabel: string;
    onApplyTour: () => void;
    onRequestCorrection: () => void;
    onVoid: () => void;
    onEdit: () => void;
}): ReactElement {
    const showMovementActions =
        (can.perform_movement || can.cancel) &&
        assignment.available_actions.length > 0;
    const isOnVessel = assignment.current_phase?.code === 'p4';

    const canApplyTour =
        can.perform_movement && assignment.can_apply_tour_of_duty;
    const correctablePhasesCount =
        corrections?.correctable_phases.length ??
        correctionRequestContext?.correctable_phases.length ??
        0;
    const canRequestCorrection =
        can.request_correction && correctablePhasesCount > 0;
    const canEdit = can.update && assignment.is_editable;
    const canVoid = can.void;

    const showOperationalActionsCard =
        canApplyTour || canRequestCorrection || canEdit || canVoid;

    return (
        <div className="space-y-4">
            {/* 1. Operator Next Step */}
            {showMovementActions || assignment.recommended_action ? (
                <CrewRecommendedNextAction
                    assignmentId={assignment.id}
                    recommended={assignment.recommended_action}
                    availableActions={assignment.available_actions}
                    movementContext={assignment.movement_context}
                    formOptions={formOptions}
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

            {/* Fallback if no movement actions available */}
            {(can.perform_movement || can.cancel) &&
            assignment.available_actions.length === 0 &&
            assignment.status !== 'completed' &&
            assignment.status !== 'cancelled' ? (
                <Card className="border-border/80 dark:border-white/10">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Movement Actions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-xs text-muted-foreground">
                            No movement actions available for the current phase.
                        </p>
                    </CardContent>
                </Card>
            ) : null}

            {/* 2. Needs Attention */}
            <CrewAssignmentAttentionCard
                warnings={assignment.warnings}
                corrections={corrections}
                canViewCorrections={can.view_corrections}
            />

            {/* 3. Mobilisation Checks */}
            {assignment.mobilisation_readiness ? (
                <CrewMobilisationReadinessCard
                    readiness={assignment.mobilisation_readiness}
                    canViewDocuments={can.view_documents}
                />
            ) : null}

            {/* 4. Relief Readiness */}
            {isOnVessel ? (
                <CrewAssignmentReliefCard
                    assignment={assignment}
                    reliefHref={reliefHref}
                    reliefActionLabel={reliefActionLabel}
                    canViewAssignments={can.view}
                />
            ) : null}

            {/* 5. Operational Actions */}
            {showOperationalActionsCard ? (
                <Card className="border-border/80 dark:border-white/10">
                    <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                        <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            Operational Actions
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-3">
                        {canApplyTour ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start text-xs font-medium"
                                onClick={onApplyTour}
                            >
                                <Clock className="mr-2 size-3.5 text-primary" />
                                Apply Tour of Duty
                            </Button>
                        ) : null}

                        {canRequestCorrection ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start text-xs font-medium"
                                onClick={onRequestCorrection}
                            >
                                <FilePenLine className="mr-2 size-3.5 text-primary" />
                                Request Correction
                            </Button>
                        ) : null}

                        {canEdit ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start text-xs font-medium"
                                onClick={onEdit}
                            >
                                <Pencil className="mr-2 size-3.5 text-primary" />
                                Edit Assignment
                            </Button>
                        ) : null}

                        {canVoid ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start border-destructive/30 text-xs font-medium text-destructive hover:bg-destructive/10 hover:text-destructive dark:border-destructive/40 dark:hover:bg-destructive/20"
                                onClick={onVoid}
                            >
                                <Trash2 className="mr-2 size-3.5 text-destructive" />
                                Void Assignment
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
