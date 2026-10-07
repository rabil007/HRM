import { CheckCircle2, CircleDot } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { RequirementWorkflowTimeline } from '@/types/recruitment';

type Props = {
    timeline: RequirementWorkflowTimeline;
};

export function RequirementWorkflowTimelineCard({ timeline }: Props) {
    return (
        <Card className="overflow-hidden border-border/70 shadow-xs">
            <CardHeader className="space-y-2 pb-3">
                <CardTitle className="text-base font-semibold">
                    Workflow timeline
                </CardTitle>
                <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-xs">
                    <p className="text-muted-foreground">
                        Current stage:{' '}
                        <span className="font-semibold text-foreground">
                            {timeline.current_stage_label}
                        </span>
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Next expected action:{' '}
                        <span className="font-semibold text-foreground">
                            {timeline.next_expected_action_label ?? 'None'}
                        </span>
                    </p>
                </div>
            </CardHeader>
            <CardContent className="pt-0">
                {timeline.events.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No workflow events recorded yet.
                    </p>
                ) : (
                    <ol className="relative space-y-4 border-l border-border/70 pl-4">
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
                                            'rounded-lg border px-3 py-2',
                                            event.state === 'current'
                                                ? 'border-primary/30 bg-primary/5'
                                                : 'border-border/60 bg-muted/20',
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
                                                {event.reason}
                                            </p>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
