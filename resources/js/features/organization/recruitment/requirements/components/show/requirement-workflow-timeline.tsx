import { CheckCircle2, CircleDot, GitBranch } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RequirementWorkflowTimeline } from '@/types/recruitment';

type Props = {
    timeline: RequirementWorkflowTimeline;
};

export function RequirementWorkflowTimeline({ timeline }: Props) {
    return (
        <div data-requirement-workflow-section className="space-y-3">
            <h3 className="flex items-center gap-1.5 text-[11px] font-bold tracking-wider text-muted-foreground/80 uppercase">
                <GitBranch
                    className="h-3.5 w-3.5 text-primary"
                    aria-hidden="true"
                />
                <span>Workflow timeline</span>
            </h3>

            {timeline.events.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No workflow events recorded yet.
                </p>
            ) : (
                <ol className="relative space-y-2.5 border-l border-border/70 pl-4">
                    {timeline.events.map((event) => {
                        const Icon =
                            event.state === 'current'
                                ? CircleDot
                                : CheckCircle2;

                        return (
                            <li key={event.id} className="relative">
                                <span
                                    className={cn(
                                        'absolute top-0.5 -left-[1.35rem] flex h-4 w-4 items-center justify-center rounded-full bg-background',
                                        event.state === 'current'
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <Icon
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div
                                    className={cn(
                                        'rounded-lg px-2.5 py-2',
                                        event.state === 'current'
                                            ? 'border border-primary/30 bg-primary/5'
                                            : 'border border-transparent bg-muted/15',
                                    )}
                                >
                                    <p className="text-sm font-medium text-foreground">
                                        {event.label}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        {event.occurred_at_formatted}
                                        {event.actor_name
                                            ? ` · ${event.actor_name}`
                                            : ''}
                                    </p>
                                    {event.reason ? (
                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            Reason: {event.reason}
                                        </p>
                                    ) : null}
                                    {event.previous_recruiter_name ||
                                    event.new_recruiter_name ? (
                                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                            {event.previous_recruiter_name
                                                ? `Previous recruiter: ${event.previous_recruiter_name}`
                                                : null}
                                            {event.previous_recruiter_name &&
                                            event.new_recruiter_name
                                                ? ' · '
                                                : null}
                                            {event.new_recruiter_name
                                                ? `New recruiter: ${event.new_recruiter_name}`
                                                : null}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}
