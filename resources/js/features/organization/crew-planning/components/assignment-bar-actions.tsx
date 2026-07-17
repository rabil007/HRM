import { ExternalLink, Pencil, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
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
                        Open Current Crew
                    </a>
                </Button>
            </div>
        );
    }

    if (!can.update && !can.delete) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2 border-t pt-2">
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
