import { router } from '@inertiajs/react';
import { ExternalLink, Loader2, Pencil, Play, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { startMobilisation } from '@/actions/App/Http/Controllers/Organization/CrewPlanningAssignmentController';
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
    const [isStarting, setIsStarting] = useState(false);

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

    const canStart = Boolean(can.start_assignment) && bar.employee_id !== null;

    if (!can.update && !can.delete && !canStart) {
        return null;
    }

    const handleStartMobilisation = (): void => {
        setIsStarting(true);
        router.post(
            startMobilisation.url(bar.id),
            {},
            {
                preserveScroll: true,
                onError: () => setIsStarting(false),
                onFinish: () => setIsStarting(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-2 border-t pt-2">
            {canStart ? (
                <Button
                    size="sm"
                    className="h-7 w-full gap-1 rounded-lg text-xs"
                    disabled={isStarting}
                    onClick={handleStartMobilisation}
                >
                    {isStarting ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                    ) : (
                        <Play className="h-3 w-3" />
                    )}
                    Start Mobilisation
                </Button>
            ) : null}
            {can.update || can.delete ? (
                <div className="flex gap-2">
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
            ) : null}
        </div>
    );
}
