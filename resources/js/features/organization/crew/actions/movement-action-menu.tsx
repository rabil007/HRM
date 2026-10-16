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
import { cn } from '@/lib/utils';
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
    triggerLabel = 'Record Movement',
    excludeActions = [],
    highlightedAction = null,
}: {
    assignmentId: number;
    availableActions: string[];
    movementContext: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    size?: 'default' | 'sm';
    triggerLabel?: string;
    excludeActions?: string[];
    /** Optional recommended action — emphasized in the menu, not split out as a separate button. */
    highlightedAction?: CrewMovementAction | null;
}): ReactElement | null {
    const [selectedAction, setSelectedAction] =
        useState<CrewMovementAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const menuActions = availableActions.filter(
        (action) => !excludeActions.includes(action),
    );

    if (menuActions.length === 0) {
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
                            title={triggerLabel}
                            aria-label={triggerLabel}
                        >
                            <ArrowRightLeft className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" variant="outline">
                            {triggerLabel}
                            <ChevronDown className="h-4 w-4" />
                        </Button>
                    )}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    {menuActions.map((actionValue) => {
                        const action = actionValue as CrewMovementAction;
                        const isRecommended = action === highlightedAction;

                        return (
                            <DropdownMenuItem
                                key={action}
                                variant={
                                    action === 'cancel_assignment'
                                        ? 'destructive'
                                        : 'default'
                                }
                                className={cn(isRecommended && 'font-medium')}
                                onClick={() => openAction(action)}
                            >
                                {CREW_MOVEMENT_ACTION_LABELS[action] ?? action}
                                {isRecommended ? (
                                    <span className="ml-auto text-xs font-normal text-muted-foreground">
                                        Next
                                    </span>
                                ) : null}
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
