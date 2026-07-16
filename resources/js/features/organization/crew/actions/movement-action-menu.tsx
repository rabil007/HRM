import { ChevronDown } from 'lucide-react';
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
} from '../types';
import { CREW_MOVEMENT_ACTION_LABELS } from '../types';
import { MovementActionDialog } from './movement-action-dialog';

export function MovementActionMenu({
    assignmentId,
    availableActions,
    currentPhase,
    formOptions,
    defaultVesselId,
    defaultRankId,
    defaultClientId,
    defaultVisaTypeId,
    defaultPlannedSignoffAt,
}: {
    assignmentId: number;
    availableActions: string[];
    currentPhase: { code: string; label: string } | null;
    formOptions?: CrewAssignmentFormOptions;
    defaultVesselId?: number | null;
    defaultRankId?: number | null;
    defaultClientId?: number | null;
    defaultVisaTypeId?: number | null;
    defaultPlannedSignoffAt?: string | null;
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

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button>
                        Record Movement
                        <ChevronDown className="h-4 w-4" />
                    </Button>
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
                                {CREW_MOVEMENT_ACTION_LABELS[action] ??
                                    action}
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
                currentPhase={currentPhase}
                formOptions={formOptions}
                defaultVesselId={defaultVesselId}
                defaultRankId={defaultRankId}
                defaultClientId={defaultClientId}
                defaultVisaTypeId={defaultVisaTypeId}
                defaultPlannedSignoffAt={defaultPlannedSignoffAt}
            />
        </>
    );
}
