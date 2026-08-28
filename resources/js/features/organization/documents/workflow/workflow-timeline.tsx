import { CheckCircle2, Circle, XCircle } from 'lucide-react';
import type { WorkflowStageItem } from '@/features/organization/documents/workflow/types';
import { WorkflowStatusBadge } from '@/features/organization/documents/workflow/workflow-status-badge';
import { cn } from '@/lib/utils';

function taskIcon(status: string) {
    if (status === 'completed') {
        return <CheckCircle2 className="h-4 w-4 text-emerald-600" />;
    }

    if (status === 'rejected') {
        return <XCircle className="h-4 w-4 text-red-600" />;
    }

    if (status === 'skipped' || status === 'cancelled') {
        return <Circle className="h-4 w-4 text-muted-foreground/50" />;
    }

    return <Circle className="h-4 w-4 text-blue-600" />;
}

export function WorkflowTimeline({ stages }: { stages: WorkflowStageItem[] }) {
    return (
        <div className="space-y-6">
            {stages.map((stage) => (
                <div key={stage.id} className="space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-sm font-semibold">
                            Stage {stage.sequence} — {stage.action_label}
                        </h3>
                        <WorkflowStatusBadge
                            status={stage.status}
                            label={stage.status_label}
                        />
                        <span className="text-xs text-muted-foreground">
                            {stage.completion_rule_label} must complete
                        </span>
                    </div>
                    <ul className="space-y-2 border-l border-border/60 pl-4">
                        {stage.tasks.map((task) => (
                            <li
                                key={task.id}
                                className={cn(
                                    'flex items-start gap-2 text-sm',
                                    task.status === 'pending' &&
                                        stage.status === 'active' &&
                                        'font-medium',
                                )}
                            >
                                <span className="mt-0.5">
                                    {taskIcon(task.status)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span>{task.assignee_name}</span>
                                        <WorkflowStatusBadge
                                            status={task.status}
                                            label={task.status_label}
                                        />
                                    </div>
                                    {task.decision_notes ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {task.decision_notes}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}
