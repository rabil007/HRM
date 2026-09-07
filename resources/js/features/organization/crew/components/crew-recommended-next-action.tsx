import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementContext,
    CrewRecommendedAction,
} from '@/features/organization/crew/types';
import { CREW_MOVEMENT_ACTION_LABELS } from '@/features/organization/crew/types';

export function CrewRecommendedNextAction({
    assignmentId,
    recommended,
    availableActions,
    movementContext,
    formOptions,
    canViewDocuments,
    canViewPlanning,
}: {
    assignmentId: number;
    recommended: CrewRecommendedAction | null;
    availableActions: string[];
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    canViewDocuments: boolean;
    canViewPlanning: boolean;
}): ReactElement | null {
    const [selectedAction, setSelectedAction] =
        useState<CrewMovementAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    if (availableActions.length === 0 && recommended === null) {
        return null;
    }

    const openAction = (action: string): void => {
        setSelectedAction(action as CrewMovementAction);
        setDialogOpen(true);
    };

    const recommendedMovement =
        recommended?.type === 'movement' && recommended.action
            ? recommended.action
            : null;
    const recommendedHref =
        recommended?.href &&
        ((recommended.type === 'readiness' && canViewDocuments) ||
            (recommended.type === 'relief' && canViewPlanning) ||
            recommended.type === 'movement')
            ? recommended.href
            : null;

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">
                    Recommended Next Action
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {recommended ? (
                    <>
                        <div>
                            <p className="text-sm font-semibold text-foreground">
                                {recommended.label}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {recommended.reason}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {recommendedMovement ? (
                                <Button
                                    type="button"
                                    onClick={() =>
                                        openAction(recommendedMovement)
                                    }
                                >
                                    {CREW_MOVEMENT_ACTION_LABELS[
                                        recommendedMovement as CrewMovementAction
                                    ] ?? recommended.label}{' '}
                                    →
                                </Button>
                            ) : null}
                            {recommendedHref && !recommendedMovement ? (
                                <Button asChild>
                                    <Link href={recommendedHref}>
                                        {recommended.type === 'readiness'
                                            ? 'Open Documents'
                                            : recommended.label}
                                    </Link>
                                </Button>
                            ) : null}
                            {recommended.anyway_action ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        openAction(recommended.anyway_action!)
                                    }
                                >
                                    {recommended.anyway_label ??
                                        'Approve Mobilisation Anyway'}
                                </Button>
                            ) : null}
                            <MovementActionMenu
                                assignmentId={assignmentId}
                                availableActions={availableActions}
                                movementContext={movementContext}
                                formOptions={formOptions}
                                triggerLabel="More Actions"
                                excludeActions={
                                    recommendedMovement
                                        ? [recommendedMovement]
                                        : []
                                }
                            />
                        </div>
                    </>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm text-muted-foreground">
                            Choose any allowed movement. Recommendations are
                            guidance only.
                        </p>
                        <MovementActionMenu
                            assignmentId={assignmentId}
                            availableActions={availableActions}
                            movementContext={movementContext}
                            formOptions={formOptions}
                            triggerLabel="Record Movement"
                        />
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    You can ignore this recommendation and use another allowed
                    action.
                </p>
            </CardContent>
            <MovementActionDialog
                open={dialogOpen}
                onOpenChange={(open) => {
                    setDialogOpen(open);

                    if (!open) {
                        setSelectedAction(null);
                    }
                }}
                action={selectedAction}
                assignmentId={assignmentId}
                movementContext={movementContext}
                formOptions={formOptions}
            />
        </Card>
    );
}
