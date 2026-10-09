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
    onOverrideCorrection,
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
    onOverrideCorrection?: () => void;
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
    const canOverrideCorrection =
        can.override_corrections && correctablePhasesCount > 0;
    const canEdit = can.update && assignment.is_editable;
    const canVoid = can.void;

    const showOtherActionsCard =
        canApplyTour ||
        canRequestCorrection ||
        canOverrideCorrection ||
        canEdit ||
        canVoid;

    return (
        <div className="space-y-5">
            {/* A. Recommended Next Action */}
            <section aria-label="Recommended next action" className="space-y-3">
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
                                No movement actions available for the current
                                phase.
                            </p>
                        </CardContent>
                    </Card>
                ) : null}
            </section>

            {/* B. Needs Attention / Readiness */}
            <section
                aria-label="Needs attention and readiness"
                className="space-y-3"
            >
                <p className="px-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                    Needs Attention / Readiness
                </p>
                <CrewAssignmentAttentionCard
                    warnings={assignment.warnings}
                    corrections={corrections}
                    canViewCorrections={can.view_corrections}
                />
                {assignment.mobilisation_readiness ? (
                    <CrewMobilisationReadinessCard
                        readiness={assignment.mobilisation_readiness}
                        canViewDocuments={can.view_documents}
                    />
                ) : null}
                {isOnVessel ? (
                    <CrewAssignmentReliefCard
                        assignment={assignment}
                        reliefHref={reliefHref}
                        reliefActionLabel={reliefActionLabel}
                        canViewAssignments={can.view}
                    />
                ) : null}
            </section>

            {/* C. Other Actions */}
            {showOtherActionsCard ? (
                <section aria-label="Other actions">
                    <Card className="border-border/80 dark:border-white/10">
                        <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                            <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                Other Actions
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

                            {canOverrideCorrection ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full justify-start border-amber-500/30 text-xs font-medium text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:text-amber-400 dark:hover:bg-amber-500/20"
                                    onClick={onOverrideCorrection}
                                >
                                    <FilePenLine className="mr-2 size-3.5 text-amber-500" />
                                    Correct Movement
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
                </section>
            ) : null}
        </div>
    );
}
