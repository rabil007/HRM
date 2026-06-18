import { Pencil, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
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
