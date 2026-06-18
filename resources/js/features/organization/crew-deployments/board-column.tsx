import { useDroppable } from '@dnd-kit/core';
import { Package } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { DeploymentCard } from '@/features/organization/crew-deployments/deployment-card';
import type { DeploymentItem, DeploymentPagePermissions } from '@/features/organization/crew-deployments/types';
import { cn } from '@/lib/utils';

const COLUMN_STYLES: Record<
    string,
    { dot: string; countClass: string; headerBorder: string; dropHighlight: string }
> = {
    arrived: {
        dot: 'bg-sky-500',
        countClass: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
        headerBorder: 'border-sky-500/30',
        dropHighlight: 'ring-2 ring-sky-400/50 bg-sky-500/5',
    },
    join_standby: {
        dot: 'bg-amber-500',
        countClass: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
        headerBorder: 'border-amber-500/30',
        dropHighlight: 'ring-2 ring-amber-400/50 bg-amber-500/5',
    },
    on_vessel: {
        dot: 'bg-emerald-500',
        countClass: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        headerBorder: 'border-emerald-500/30',
        dropHighlight: 'ring-2 ring-emerald-400/50 bg-emerald-500/5',
    },
    disembarked: {
        dot: 'bg-rose-500',
        countClass: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
        headerBorder: 'border-rose-500/30',
        dropHighlight: 'ring-2 ring-rose-400/50 bg-rose-500/5',
    },
    leave_standby: {
        dot: 'bg-orange-500',
        countClass: 'border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-400',
        headerBorder: 'border-orange-500/30',
        dropHighlight: 'ring-2 ring-orange-400/50 bg-orange-500/5',
    },
    travel: {
        dot: 'bg-violet-500',
        countClass: 'border-violet-500/30 bg-violet-500/10 text-violet-600 dark:text-violet-400',
        headerBorder: 'border-violet-500/30',
        dropHighlight: 'ring-2 ring-violet-400/50 bg-violet-500/5',
    },
    in_home: {
        dot: 'bg-teal-500',
        countClass: 'border-teal-500/30 bg-teal-500/10 text-teal-600 dark:text-teal-400',
        headerBorder: 'border-teal-500/30',
        dropHighlight: 'ring-2 ring-teal-400/50 bg-teal-500/5',
    },
};

type Props = {
    status: string;
    label: string;
    count: number;
    deployments: DeploymentItem[];
    can: DeploymentPagePermissions;
    onEdit: (deployment: DeploymentItem) => void;
    onDelete: (deployment: DeploymentItem) => void;
    backQuery?: Record<string, string>;
};

export function BoardColumn({
    status,
    label,
    count,
    deployments,
    can,
    onEdit,
    onDelete,
    backQuery,
}: Props): ReactElement {
    const styles = COLUMN_STYLES[status] ?? COLUMN_STYLES.unknown;

    const { setNodeRef, isOver } = useDroppable({ id: status });

    return (
        <div className="flex min-w-[300px] max-w-[300px] flex-col gap-2">
            {/* Column header */}
            <div
                className={cn(
                    'flex items-center justify-between rounded-xl border px-3.5 py-2.5',
                    'bg-card shadow-sm',
                    styles.headerBorder,
                )}
            >
                <div className="flex items-center gap-2">
                    <span className={cn('h-2 w-2 rounded-full', styles.dot)} />
                    <span className="text-xs font-bold uppercase tracking-wider text-foreground/80">
                        {label}
                    </span>
                </div>
                <Badge
                    variant="outline"
                    className={cn(
                        'h-5 min-w-[1.5rem] justify-center px-1.5 text-[10px] font-bold tabular-nums',
                        styles.countClass,
                    )}
                >
                    {count}
                </Badge>
            </div>

            {/* Drop zone body */}
            <div
                ref={setNodeRef}
                className={cn(
                    'flex-1 rounded-xl border border-border/40 bg-muted/30 p-2 transition-all duration-150',
                    'dark:bg-white/[0.02]',
                    isOver && styles.dropHighlight,
                    isOver && 'border-transparent',
                )}
                style={{ minHeight: '120px' }}
            >
                {deployments.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-10 text-center">
                        <Package
                            className={cn(
                                'mb-2 h-8 w-8 transition-colors',
                                isOver ? 'text-foreground/40' : 'text-muted-foreground/20',
                            )}
                        />
                        <p className="text-[11px] text-muted-foreground/50">
                            {isOver ? 'Drop here' : 'Empty'}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {deployments.map((deployment) => (
                            <DeploymentCard
                                key={deployment.id}
                                deployment={deployment}
                                can={can}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                backQuery={backQuery}
                            />
                        ))}

                        {isOver ? (
                            <div className="h-1.5 rounded-full bg-current opacity-20" />
                        ) : null}
                    </div>
                )}
            </div>
        </div>
    );
}
