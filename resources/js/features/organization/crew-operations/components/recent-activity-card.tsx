import { Activity, Clock } from 'lucide-react';
import type { ReactElement } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsRecentActivityItem } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';

function eventDotColor(event: string | null): string {
    if (!event) {
        return 'bg-muted-foreground/40';
    }

    switch (event.toLowerCase()) {
        case 'created':
            return 'bg-emerald-500';
        case 'updated':
            return 'bg-teal-400';
        case 'deleted':
            return 'bg-destructive';
        default:
            return 'bg-primary/60';
    }
}

function eventLabel(event: string | null): string {
    if (!event) {
        return 'changed';
    }

    return event.toLowerCase();
}

function relativeTime(createdAt: string | null): string {
    if (!createdAt) {
        return '';
    }

    const date = new Date(createdAt);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60_000);

    if (diffMins < 1) {
        return 'just now';
    }

    if (diffMins < 60) {
        return `${diffMins}m ago`;
    }

    const diffHours = Math.floor(diffMins / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    return `${diffDays}d ago`;
}

export function RecentActivityCard({
    activities,
}: {
    activities: CrewOperationsRecentActivityItem[];
}): ReactElement {
    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-xl border border-border/60 bg-muted/30 dark:border-white/8 dark:bg-white/5">
                        <Activity className="size-4 text-muted-foreground" />
                    </div>
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Recent Activity
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Latest crew assignment changes
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="pt-4">
                {activities.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                        No recent activity to show
                    </p>
                ) : (
                    <ol className="relative space-y-0 before:absolute before:inset-y-2 before:left-[7px] before:w-px before:bg-border/50 dark:before:bg-white/8">
                        {activities.map((activity, index) => (
                            <li
                                key={activity.id}
                                className={cn(
                                    'relative flex items-start gap-3 py-2.5 pl-6',
                                    index < activities.length - 1 &&
                                        'border-b border-border/30 dark:border-white/5',
                                )}
                            >
                                {/* Timeline dot */}
                                <span
                                    className={cn(
                                        'absolute top-3.5 left-0 size-3.5 rounded-full border-2 border-background',
                                        eventDotColor(activity.event),
                                    )}
                                />

                                <div className="min-w-0 flex-1">
                                    <p className="text-sm leading-snug text-foreground/80">
                                        {activity.causer ? (
                                            <span className="font-semibold">
                                                {activity.causer.name}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/60 italic">
                                                System
                                            </span>
                                        )}{' '}
                                        <span className="text-muted-foreground/70">
                                            {eventLabel(activity.event)}
                                        </span>{' '}
                                        {activity.description ? (
                                            <span className="font-medium text-foreground/70">
                                                {activity.description}
                                            </span>
                                        ) : null}
                                    </p>

                                    <p className="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground/50">
                                        <Clock className="size-2.5" />
                                        {relativeTime(
                                            activity.created_at as
                                                | string
                                                | null,
                                        )}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
