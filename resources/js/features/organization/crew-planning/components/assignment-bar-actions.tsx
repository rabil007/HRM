import { ExternalLink, Pencil, Play, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';

import { Button } from '@/components/ui/button';
import { create as createCrewAssignment } from '@/routes/organization/crew-assignments';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import type {
    GanttBar,
    PlanningBackQuery,
    PlanningPagePermissions,
} from '../types';

type Props = {
    bar: GanttBar;
    can: PlanningPagePermissions;
    planningBackQuery?: PlanningBackQuery | null;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

export function AssignmentBarActions({
    bar,
    can,
    planningBackQuery = null,
    onEdit,
    onDelete,
}: Props): ReactElement | null {
    if (bar.crew_assignment_id !== null) {
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
                        Open Crew Assignment
                    </a>
                </Button>
            </div>
        );
    }

    const canStartAssignment =
        (can.start_assignment ?? false) &&
        bar.employee_id !== null &&
        bar.crew_assignment_id === null;

    if (!can.update && !can.delete && !canStartAssignment) {
        return null;
    }

    const startHref = createCrewAssignment.url({
        query: {
            planning_assignment_id: bar.id,
            ...(planningBackQuery ?? {}),
        },
    });

    return (
        <div className="flex flex-wrap gap-2 border-t pt-2">
            {canStartAssignment ? (
                <Button
                    size="sm"
                    variant="default"
                    className="h-7 w-full gap-1 rounded-lg text-xs font-semibold"
                    asChild
                >
                    <a href={startHref}>
                        <Play className="h-3.5 w-3.5" />
                        Start Assignment
                    </a>
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
    );
}
