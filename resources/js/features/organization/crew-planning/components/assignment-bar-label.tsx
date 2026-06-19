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

export function AssignmentBarLabel({ bar, start, end, className }: Props): ReactElement {
    const isVacant = bar.employee_id === null;
    const totalDays = assignmentDurationDays(start, end);

    return (
        <>
            {isVacant ? (
                <span className="min-w-0 flex-1 truncate italic text-muted-foreground/60">Vacant</span>
            ) : (
                <>
                    <AssignmentBarPopover.Avatar name={bar.employee_name} size="sm" bar={bar} />
                    <span className="min-w-0 flex-1 truncate">{bar.employee_name}</span>
                </>
            )}
            <span
                className={cn(
                    'shrink-0 rounded bg-background/50 px-1 py-0.5 text-[10px] font-semibold tabular-nums text-muted-foreground',
                    className,
                )}
                title={`${totalDays} days`}
            >
                {totalDays}d
            </span>
        </>
    );
}
