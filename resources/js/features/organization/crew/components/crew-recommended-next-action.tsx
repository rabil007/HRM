import { Link } from '@inertiajs/react';
import { ArrowRight, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import { CrewOperationalStatePanel } from '@/features/organization/crew/components/crew-operational-state-panel';
import { resolveOperatorNextStepButtons } from '@/features/organization/crew/lib/crew-operational-state';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementContext,
    CrewRecommendedAction,
} from '@/features/organization/crew/types';

export function CrewRecommendedNextAction({
    assignmentId,
    recommended,
    availableActions,
    movementContext,
    formOptions,
    canViewDocuments,
    canViewPlanning,
    canPerformMovement = true,
    canCancel = false,
    currentPhase,
    status,
    statusLabel,
    vesselName,
}: {
    assignmentId: number;
    recommended: CrewRecommendedAction | null;
    availableActions: string[];
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    canViewDocuments: boolean;
    canViewPlanning: boolean;
    canPerformMovement?: boolean;
    canCancel?: boolean;
    currentPhase?: {
        code: string;
        label: string;
        status?: string;
    } | null;
    status?: string;
    statusLabel?: string;
    vesselName?: string | null;
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

    const buttons = resolveOperatorNextStepButtons({
        recommended,
        availableActions,
        permissions: {
            perform_movement: canPerformMovement,
            cancel: canCancel,
        },
        canViewDocuments,
        canViewPlanning,
    });

    return (
        <Card className="overflow-hidden border-primary/20 bg-primary/3 shadow-xs dark:border-primary/20 dark:bg-primary/5">
            <CardHeader className="border-b border-primary/10 pb-3">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <span className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Sparkles className="size-4" />
                        </span>
                        <div>
                            <CardTitle className="text-base font-semibold">
                                Operator next step
                            </CardTitle>
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                Recommended from the current assignment state
                            </p>
                        </div>
                    </div>
                    {recommended ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-[10px] font-semibold text-primary">
                            <span className="size-1.5 rounded-full bg-primary" />
                            Recommended
                        </span>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-3.5 pt-4">
                <CrewOperationalStatePanel
                    assignment={{
                        current_phase: currentPhase
                            ? {
                                  ...currentPhase,
                                  status: currentPhase.status ?? 'active',
                              }
                            : null,
                        status: status ?? 'active',
                        status_label: statusLabel ?? '',
                        available_actions: availableActions,
                        vessel: vesselName ? { id: 0, name: vesselName } : null,
                    }}
                    recommended={recommended}
                    permissions={{
                        perform_movement: canPerformMovement,
                        cancel: canCancel,
                    }}
                />

                <div className="space-y-2 border-t border-primary/10 pt-3">
                    {buttons.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-2.5">
                            {buttons.map((button) => {
                                if (button.kind === 'href' && button.href) {
                                    return (
                                        <Button
                                            key={`href-${button.href}`}
                                            asChild
                                            className="font-medium shadow-xs"
                                        >
                                            <Link href={button.href}>
                                                <span>{button.label}</span>
                                                <ArrowRight className="ml-1.5 size-4" />
                                            </Link>
                                        </Button>
                                    );
                                }

                                if (!button.action) {
                                    return null;
                                }

                                const isPrimary =
                                    button.kind === 'movement' &&
                                    recommended?.type === 'movement' &&
                                    button.action === recommended.action;
                                const isAnyway = button.kind === 'anyway';
                                const isCancel = button.kind === 'cancel';

                                return (
                                    <Button
                                        key={`${button.kind}-${button.action}`}
                                        type="button"
                                        variant={
                                            isPrimary ? 'default' : 'outline'
                                        }
                                        className={
                                            isAnyway
                                                ? 'border-amber-500/30 text-amber-800 hover:bg-amber-500/10 dark:text-amber-200'
                                                : isCancel
                                                  ? 'border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive dark:border-destructive/40 dark:hover:bg-destructive/20'
                                                  : isPrimary
                                                    ? 'font-medium shadow-xs'
                                                    : undefined
                                        }
                                        onClick={() =>
                                            openAction(button.action!)
                                        }
                                    >
                                        <span>{button.label}</span>
                                        {isPrimary ? (
                                            <ArrowRight className="ml-1.5 size-4" />
                                        ) : null}
                                    </Button>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Choose any allowed movement. Recommendations are
                            guidance only.
                        </p>
                    )}
                </div>
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
