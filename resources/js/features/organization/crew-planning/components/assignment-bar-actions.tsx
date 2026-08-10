import { router } from '@inertiajs/react';
import { ExternalLink, Pencil, Trash2, UserPlus } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { createCrewAssignment } from '@/routes/organization/crew-planning/assignments';
import type { GanttBar, PlanningPagePermissions } from '../types';

type Props = {
    bar: GanttBar;
    can: PlanningPagePermissions;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

export function AssignmentBarActions({
    bar,
    can,
    onEdit,
    onDelete,
}: Props): ReactElement | null {
    const [confirmOpen, setConfirmOpen] = useState(false);

    if (bar.is_assigned && bar.crew_assignment_id !== null) {
        return (
            <div className="flex flex-wrap gap-2 border-t pt-2">
                <Button
                    size="sm"
                    variant="outline"
                    className="h-7 flex-1 gap-1 rounded-lg text-xs"
                    asChild
                >
                    <a href={showAssignment.url(bar.crew_assignment_id)}>
                        <ExternalLink className="h-3 w-3" />
                        Open Crew Assignments
                    </a>
                </Button>
            </div>
        );
    }

    const canCreateAssignment =
        (can.create_assignment ?? false) &&
        bar.employee_id !== null &&
        bar.crew_assignment_id === null;

    if (!can.update && !can.delete && !canCreateAssignment) {
        return null;
    }

    const handleCreateAssignment = () => {
        router.post(createCrewAssignment.url(bar.id));
    };

    return (
        <>
            <div className="flex flex-wrap gap-2 border-t pt-2">
                {canCreateAssignment ? (
                    <Button
                        size="sm"
                        variant="default"
                        className="h-7 w-full gap-1 rounded-lg text-xs font-semibold"
                        onClick={() => setConfirmOpen(true)}
                    >
                        <UserPlus className="h-3.5 w-3.5" />
                        Create Crew Assignment
                    </Button>
                ) : null}
                {can.update ? (
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-7 flex-1 gap-1 rounded-lg text-xs"
                        onClick={() => onEdit?.(bar)}
                    >
                        <Pencil className="h-3 w-3" />
                        Edit
                    </Button>
                ) : null}
                {can.delete ? (
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-7 flex-1 gap-1 rounded-lg text-xs text-destructive hover:text-destructive"
                        onClick={() => onDelete?.(bar)}
                    >
                        <Trash2 className="h-3 w-3" />
                        Delete
                    </Button>
                ) : null}
            </div>

            <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Create Crew Assignment?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This will create a draft Crew Assignment from this
                            planning record. After conversion, operational
                            changes must be made through Crew Assignments.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleCreateAssignment}>
                            Create Assignment
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
