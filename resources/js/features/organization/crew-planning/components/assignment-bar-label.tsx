import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { assignmentDurationDays } from '../lib/planning-gantt-math';
import type { GanttBar } from '../types';
import { AssignmentBarPopover } from './assignment-bar-popover';

type Props = {
    bar: GanttBar;
    start: string;
    end: string;
    className?: string;
};

export function AssignmentBarLabel({
    bar,
    start,
    end,
    className,
}: Props): ReactElement {
    const isVacant = bar.employee_id === null;
    const totalDays = assignmentDurationDays(start, end);

    return (
        <>
            {isVacant ? (
                <span className="min-w-0 flex-1 truncate text-muted-foreground/60 italic">
                    Vacant
                </span>
            ) : (
                <>
                    <AssignmentBarPopover.Avatar
                        name={bar.employee_name}
                        size="sm"
                        bar={bar}
                    />
                    <span className="min-w-0 flex-1 truncate">
                        {bar.employee_name}
                    </span>
                </>
            )}
            <span
                className={cn(
                    'shrink-0 rounded bg-background/50 px-1 py-0.5 text-[10px] font-semibold text-muted-foreground tabular-nums',
                    className,
                )}
                title={`${totalDays} days`}
            >
                {totalDays}d
            </span>
        </>
    );
}
