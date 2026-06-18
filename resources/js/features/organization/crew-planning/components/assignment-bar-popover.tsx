import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { AssignmentBarActions } from './assignment-bar-actions';

function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

function formatDate(dateStr: string): string {
    return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function Avatar({ name, size }: { name: string; size: 'sm' | 'md' }): ReactElement {
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center rounded-full bg-primary/15 font-bold text-primary',
                size === 'sm' && 'h-4 w-4 text-[9px]',
                size === 'md' && 'h-8 w-8 text-sm',
            )}
        >
            {initials(name)}
        </span>
    );
}

function InfoRow({ label, value }: { label: string; value: string }): ReactElement {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}

type Props = {
    bar: GanttBar;
    can: PlanningPagePermissions;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

function AssignmentBarPopoverContent({
    bar,
    can,
    onEdit,
    onDelete,
}: Props): ReactElement {
    return (
        <>
            <div className="flex items-center gap-3 border-b bg-muted/30 px-4 py-3">
                <Avatar name={bar.employee_name} size="md" />
                <div className="min-w-0">
                    <p className="truncate font-semibold">{bar.employee_name}</p>
                    {bar.rank_name ? (
                        <p className="truncate text-xs text-muted-foreground">{bar.rank_name}</p>
                    ) : null}
                </div>
            </div>
            <div className="space-y-1.5 px-4 py-3 text-xs">
                {bar.vessel_name ? <InfoRow label="Vessel" value={bar.vessel_name} /> : null}
                <InfoRow label="Planned join" value={formatDate(bar.planned_join_date)} />
                <InfoRow label="Planned leave" value={formatDate(bar.planned_leave_date)} />
                {bar.notes ? (
                    <p className="mt-1 rounded-md bg-muted px-2 py-1.5 text-muted-foreground">{bar.notes}</p>
                ) : null}
            </div>
            {(can.update || can.delete) ? (
                <div className="border-t px-4 pb-3">
                    <AssignmentBarActions
                        bar={bar}
                        can={can}
                        onEdit={onEdit}
                        onDelete={onDelete}
                    />
                </div>
            ) : null}
        </>
    );
}

export const AssignmentBarPopover = Object.assign(AssignmentBarPopoverContent, { Avatar });
