import type { ReactElement } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { barAvatarClass, barSurfaceClass } from '../lib/assignment-bar-styles';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { AssignmentBarPopover } from './assignment-bar-popover';

type Props = {
    bar: GanttBar;
    style: React.CSSProperties;
    highlighted: boolean;
    can: PlanningPagePermissions;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

export function ReadOnlyAssignmentBar({
    bar,
    style,
    highlighted,
    can,
    onEdit,
    onDelete,
}: Props): ReactElement {
    const isVacant = bar.employee_id === null;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div
                    className={cn(
                        'absolute top-1.5 bottom-1.5 flex items-center gap-1.5 rounded-md px-2 text-xs font-medium text-foreground',
                        barSurfaceClass(bar),
                        highlighted && 'ring-2 ring-offset-1 ring-amber-400',
                    )}
                    style={style}
                >
                    {isVacant ? (
                        <span className="truncate italic text-muted-foreground/60">Vacant</span>
                    ) : (
                        <>
                            <AssignmentBarPopover.Avatar name={bar.employee_name} size="sm" bar={bar} />
                            <span className="truncate">{bar.employee_name}</span>
                        </>
                    )}
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className="w-68 overflow-hidden p-0">
                <AssignmentBarPopover
                    bar={bar}
                    can={can}
                    onEdit={onEdit}
                    onDelete={onDelete}
                />
            </PopoverContent>
        </Popover>
    );
}

