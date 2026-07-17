import type { ReactElement } from 'react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { barSurfaceClass } from '../lib/assignment-bar-styles';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { AssignmentBarLabel } from './assignment-bar-label';
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
    return (
        <Popover>
            <PopoverTrigger asChild>
                <div
                    className={cn(
                        'absolute top-1.5 bottom-1.5 flex items-center gap-1.5 rounded-md px-2 text-xs font-medium text-foreground',
                        barSurfaceClass(bar),
                        highlighted && 'ring-2 ring-amber-400 ring-offset-1',
                    )}
                    style={style}
                >
                    <AssignmentBarLabel
                        bar={bar}
                        start={bar.planned_join_date}
                        end={bar.end}
                    />
                </div>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                sideOffset={6}
                className="w-68 overflow-hidden p-0"
            >
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
