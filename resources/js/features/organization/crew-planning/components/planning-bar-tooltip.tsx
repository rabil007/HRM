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

function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

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

function StatusBadge({ status }: { status: string }): ReactElement {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider',
                status === 'active' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400',
                status === 'future' && 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                status === 'past' && 'bg-muted text-muted-foreground',
                status === 'draft' && 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-400',
            )}
        >
            {status}
        </span>
    );
}

function InfoRow({ label, value }: { label: string; value: string }): ReactElement {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium text-foreground">{value}</span>
        </div>
    );
}

function DeploymentBar({
    bar,
    style,
    highlighted,
}: Omit<Props, 'rangeFrom' | 'rangeTo' | 'can' | 'onEdit' | 'onDelete' | 'onConfirm'>): ReactElement {
    const barClass = cn(
        'absolute top-1.5 bottom-1.5 rounded-md flex items-center gap-1.5 px-2 text-xs font-medium cursor-pointer select-none overflow-hidden transition-all shadow-sm hover:shadow-md hover:brightness-110 active:scale-[0.98]',
        bar.status === 'active' && 'bg-emerald-500 text-white',
        bar.status === 'future' && 'bg-blue-500 text-white',
        bar.status === 'past' && 'bg-muted-foreground/20 text-foreground/50 hover:bg-muted-foreground/30',
        highlighted && 'ring-2 ring-offset-1 ring-amber-400',
    );

    const avatarClass = cn(
        'flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold leading-none',
        bar.status === 'active' && 'bg-white/20',
        bar.status === 'future' && 'bg-white/20',
        bar.status === 'past' && 'bg-foreground/10 text-foreground/40',
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div className={barClass} style={style}>
                    <span className={avatarClass}>{initials(bar.employee_name)}</span>
                    <span className="truncate">{bar.employee_name}</span>
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className="w-68 p-0 overflow-hidden">
                {/* Header */}
                <div
                    className={cn(
                        'flex items-center gap-3 px-4 py-3',
                        bar.status === 'active' && 'bg-emerald-500',
                        bar.status === 'future' && 'bg-blue-500',
                        bar.status === 'past' && 'bg-muted',
                    )}
                >
                    <div
                        className={cn(
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                            bar.status === 'active' && 'bg-white/20 text-white',
                            bar.status === 'future' && 'bg-white/20 text-white',
                            bar.status === 'past' && 'bg-muted-foreground/20 text-muted-foreground',
                        )}
                    >
                        {initials(bar.employee_name)}
                    </div>
                    <div className="min-w-0">
                        <p
                            className={cn(
                                'truncate font-semibold leading-tight',
                                bar.status !== 'past' && 'text-white',
                            )}
                        >
                            {bar.employee_name}
                        </p>
                        {bar.nationality ? (
                            <p
                                className={cn(
                                    'truncate text-xs',
                                    bar.status !== 'past' ? 'text-white/70' : 'text-muted-foreground',
                                )}
                            >
                                {bar.nationality}
                            </p>
                        ) : null}
                    </div>
                </div>
                {/* Body */}
                <div className="space-y-1.5 px-4 py-3 text-xs">
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">Status</span>
                        <StatusBadge status={bar.status} />
                    </div>
                    {bar.rank_name ? <InfoRow label="Rank" value={bar.rank_name} /> : null}
                    {bar.vessel_name ? <InfoRow label="Vessel" value={bar.vessel_name} /> : null}
                    <InfoRow label="Joined" value={formatDate(bar.joined_date)} />
                    <InfoRow label="Disembarked" value={formatDate(bar.disembarked_date)} />
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
        'absolute top-1.5 bottom-1.5 rounded-md flex items-center gap-1.5 px-2 text-xs font-medium cursor-pointer select-none overflow-hidden transition-all',
        'border border-dashed border-violet-400/70 bg-violet-50/80 text-violet-800',
        'dark:border-violet-500/60 dark:bg-violet-900/20 dark:text-violet-300',
        'hover:bg-violet-100/80 dark:hover:bg-violet-900/30',
        highlighted && 'ring-2 ring-offset-1 ring-amber-400',
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <div className={barClass} style={style}>
                    <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-violet-200/80 text-[9px] font-bold text-violet-700 dark:bg-violet-700/30 dark:text-violet-300">
                        {initials(bar.employee_name)}
                    </span>
                    <span className="truncate">{bar.employee_name}</span>
                </div>
            </PopoverTrigger>
            <PopoverContent align="start" sideOffset={6} className="w-68 overflow-hidden p-0">
                <div className="flex items-center gap-3 bg-violet-50 px-4 py-3 dark:bg-violet-900/30">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-200 text-sm font-bold text-violet-700 dark:bg-violet-700/40 dark:text-violet-300">
                        {initials(bar.employee_name)}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate font-semibold text-violet-900 dark:text-violet-200">{bar.employee_name}</p>
                        <span className="text-[10px] font-semibold uppercase tracking-wider text-violet-500 dark:text-violet-400">
                            Draft
                        </span>
                    </div>
                </div>
                <div className="space-y-1.5 px-4 py-3 text-xs">
                    {bar.rank_name ? <InfoRow label="Rank" value={bar.rank_name} /> : null}
                    {bar.vessel_name ? <InfoRow label="Vessel" value={bar.vessel_name} /> : null}
                    <InfoRow label="Planned join" value={formatDate(bar.joined_date)} />
                    <InfoRow label="Planned leave" value={formatDate(bar.disembarked_date)} />
                    {bar.notes ? (
                        <p className="mt-1 rounded-md bg-muted px-2 py-1.5 text-muted-foreground">{bar.notes}</p>
                    ) : null}
                </div>
                {(can.update || can.delete || can.confirm) ? (
                    <div className="border-t px-4 pb-3">
                        <AssignmentBarActions
                            bar={bar}
                            can={can}
                            onEdit={onEdit}
                            onDelete={onDelete}
                            onConfirm={onConfirm}
                        />
                    </div>
                ) : null}
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
