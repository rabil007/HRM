import { Link } from '@inertiajs/react';
import { ArrowRight, Sparkles } from 'lucide-react';
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
        <Card className="overflow-hidden border-primary/20 bg-primary/3 dark:border-primary/20 dark:bg-primary/5">
            <CardHeader className="border-b border-primary/10 pb-3">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <span className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Sparkles className="size-4" />
                        </span>
                        <div>
                            <CardTitle className="text-base">
                                Operator next step
                            </CardTitle>
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                Recommended from the current assignment state
                            </p>
                        </div>
                    </div>
                    {recommended ? (
                        <span className="rounded-full bg-primary/10 px-2 py-1 text-[10px] font-semibold text-primary">
                            Recommended
                        </span>
                    ) : null}
                </div>
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
                                    ] ?? recommended.label}
                                    <ArrowRight className="ml-1.5 size-4" />
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
                                        'Start Assignment Anyway'}
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
                <p className="text-[11px] text-muted-foreground/70">
                    Other permitted movements remain available under More
                    Actions.
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
