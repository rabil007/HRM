import { ArrowRightLeft, ChevronDown } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementContext,
} from '../types';
import { CREW_MOVEMENT_ACTION_LABELS } from '../types';
import { MovementActionDialog } from './movement-action-dialog';

export function MovementActionMenu({
    assignmentId,
    availableActions,
    movementContext,
    formOptions,
    size = 'default',
}: {
    assignmentId: number;
    availableActions: string[];
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    size?: 'default' | 'sm';
}): ReactElement | null {
    const [selectedAction, setSelectedAction] =
        useState<CrewMovementAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    if (availableActions.length === 0) {
        return null;
    }

    const openAction = (action: CrewMovementAction): void => {
        setSelectedAction(action);
        setDialogOpen(true);
    };

    const handleDialogOpenChange = (open: boolean): void => {
        setDialogOpen(open);

        if (!open) {
            setSelectedAction(null);
        }
    };

    const isCompact = size === 'sm';

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    {isCompact ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-white/10 dark:hover:text-zinc-100"
                            title="Record Movement"
                            aria-label="Record Movement"
                        >
                            <ArrowRightLeft className="size-4" />
                        </Button>
                    ) : (
                        <Button>
                            Record Movement
                            <ChevronDown className="h-4 w-4" />
                        </Button>
                    )}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    {availableActions.map((actionValue) => {
                        const action = actionValue as CrewMovementAction;

                        return (
                            <DropdownMenuItem
                                key={action}
                                variant={
                                    action === 'cancel_assignment'
                                        ? 'destructive'
                                        : 'default'
                                }
                                onClick={() => openAction(action)}
                            >
                                {CREW_MOVEMENT_ACTION_LABELS[action] ?? action}
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>

            <MovementActionDialog
                open={dialogOpen}
                onOpenChange={handleDialogOpenChange}
                action={selectedAction}
                assignmentId={assignmentId}
                movementContext={movementContext}
                formOptions={formOptions}
            />
        </>
    );
}
