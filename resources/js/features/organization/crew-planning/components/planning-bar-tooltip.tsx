import type { ReactElement } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { AssignmentBarActions } from './assignment-bar-actions';
import { DraggableAssignmentBar } from './draggable-assignment-bar';

type Props = {
    bar: GanttBar;
    style: React.CSSProperties;
    highlighted: boolean;
    can: PlanningPagePermissions;
    rangeFrom: Date;
    rangeTo: Date;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
    onConfirm?: (bar: GanttBar) => void;
};

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
        return 'Ongoing';
    }

    return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function DeploymentBar({
    bar,
    style,
    highlighted,
}: Omit<Props, 'rangeFrom' | 'rangeTo' | 'can' | 'onEdit' | 'onDelete' | 'onConfirm'>): ReactElement {
    const barClass = cn(
        'absolute top-1 bottom-1 rounded flex items-center px-2 text-xs font-medium cursor-pointer select-none truncate transition-opacity',
        bar.status === 'active' && 'bg-emerald-500 text-white hover:bg-emerald-600',
        bar.status === 'future' && 'bg-blue-500 text-white hover:bg-blue-600',
        bar.status === 'past' &&
            'bg-muted-foreground/30 text-foreground/60 hover:bg-muted-foreground/40',
        highlighted && 'ring-2 ring-offset-1 ring-yellow-400',
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div className={barClass} style={style}>
                    <span className="truncate">{bar.employee_name}</span>
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-64 p-3">
                <div className="space-y-1.5">
                    <p className="font-semibold">{bar.employee_name}</p>
                    {bar.nationality ? (
                        <p className="text-xs text-muted-foreground">{bar.nationality}</p>
                    ) : null}
                    <div className="space-y-1 border-t pt-1.5 text-xs">
                        {bar.rank_name ? (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Rank</span>
                                <span className="font-medium">{bar.rank_name}</span>
                            </div>
                        ) : null}
                        {bar.vessel_name ? (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Vessel</span>
                                <span className="font-medium">{bar.vessel_name}</span>
                            </div>
                        ) : null}
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Joined</span>
                            <span className="font-medium">{formatDate(bar.joined_date)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Disembarked</span>
                            <span className="font-medium">{formatDate(bar.disembarked_date)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Status</span>
                            <span
                                className={cn(
                                    'capitalize font-medium',
                                    bar.status === 'active' && 'text-emerald-600',
                                    bar.status === 'future' && 'text-blue-600',
                                    bar.status === 'past' && 'text-muted-foreground',
                                )}
                            >
                                {bar.status}
                            </span>
                        </div>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

function ReadOnlyAssignmentBar({
    bar,
    style,
    highlighted,
    can,
    onEdit,
    onDelete,
    onConfirm,
}: Omit<Props, 'rangeFrom' | 'rangeTo'>): ReactElement {
    const barClass = cn(
        'absolute top-1 bottom-1 rounded flex items-center px-2 text-xs font-medium cursor-pointer select-none truncate transition-opacity',
        'border-2 border-dashed border-blue-400 bg-blue-50 text-blue-800 hover:bg-blue-100',
        'dark:border-blue-500 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50',
        highlighted && 'ring-2 ring-offset-1 ring-yellow-400',
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div className={barClass} style={style}>
                    <span className="truncate">{bar.employee_name}</span>
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-64 p-3">
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2">
                        <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                            Draft
                        </span>
                        <p className="font-semibold">{bar.employee_name}</p>
                    </div>
                    <div className="space-y-1 border-t pt-1.5 text-xs">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Planned join</span>
                            <span className="font-medium">{formatDate(bar.joined_date)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Planned leave</span>
                            <span className="font-medium">{formatDate(bar.disembarked_date)}</span>
                        </div>
                        {bar.notes ? (
                            <p className="mt-1 text-muted-foreground">{bar.notes}</p>
                        ) : null}
                    </div>
                    <AssignmentBarActions
                        bar={bar}
                        can={can}
                        onEdit={onEdit}
                        onDelete={onDelete}
                        onConfirm={onConfirm}
                    />
                </div>
            </PopoverContent>
        </Popover>
    );
}

export function PlanningGanttBar({
    bar,
    style,
    highlighted,
    can,
    rangeFrom,
    rangeTo,
    onEdit,
    onDelete,
    onConfirm,
}: Props): ReactElement {
    if (bar.source === 'assignment' && can.update) {
        return (
            <DraggableAssignmentBar
                bar={bar}
                style={style}
                highlighted={highlighted}
                can={can}
                rangeFrom={rangeFrom}
                rangeTo={rangeTo}
                onEdit={onEdit}
                onDelete={onDelete}
                onConfirm={onConfirm}
            />
        );
    }

    if (bar.source === 'assignment') {
        return (
            <ReadOnlyAssignmentBar
                bar={bar}
                style={style}
                highlighted={highlighted}
                can={can}
                onEdit={onEdit}
                onDelete={onDelete}
                onConfirm={onConfirm}
            />
        );
    }

    return <DeploymentBar bar={bar} style={style} highlighted={highlighted} />;
}
