import { Link } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsNextDay } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

function ActivityBar({
    joins,
    signoffs,
    max,
}: {
    joins: number;
    signoffs: number;
    max: number;
}): ReactElement {
    const total = joins + signoffs;
    const normalised = max > 0 ? (total / max) * 100 : 0;
    const joinPct = total > 0 ? (joins / total) * 100 : 0;
    const signoffPct = total > 0 ? (signoffs / total) * 100 : 0;

    if (total === 0) {
        return (
            <div className="h-2 w-full rounded-full bg-muted/30 dark:bg-white/5" />
        );
    }

    return (
        <div
            className="relative h-2 w-full overflow-hidden rounded-full bg-muted/20 dark:bg-white/5"
            title={`${joins} join${joins !== 1 ? 's' : ''} · ${signoffs} sign-off${signoffs !== 1 ? 's' : ''}`}
        >
            {/* full-width track sized to max */}
            <div
                className="absolute inset-y-0 left-0 flex gap-px rounded-full overflow-hidden"
                style={{ width: `${normalised}%` }}
            >
                {joins > 0 ? (
                    <div
                        className="h-full rounded-l-full bg-teal-500"
                        style={{ width: `${joinPct}%` }}
                    />
                ) : null}
                {signoffs > 0 ? (
                    <div
                        className={cn(
                            'h-full bg-amber-400 dark:bg-amber-500',
                            joins === 0 && 'rounded-l-full',
                            'rounded-r-full',
                        )}
                        style={{ width: `${signoffPct}%` }}
                    />
                ) : null}
            </div>
        </div>
    );
}

export function NextSevenDaysCard({
    days,
    canViewPlanning,
}: {
    days: CrewOperationsNextDay[];
    canViewPlanning: boolean;
}): ReactElement {
    const hasActivity = days.some((day) => day.joins > 0 || day.signoffs > 0);
    const max = Math.max(...days.map((d) => d.joins + d.signoffs), 1);

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Next 7 Days
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Planned movements — joins &amp; sign-offs
                        </CardDescription>
                    </div>
                    {canViewPlanning ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            asChild
                        >
                            <Link href={crewPlanningIndex.url()}>
                                Open Planning
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="pt-4">
                {/* Legend */}
                <div className="mb-3 flex items-center gap-4 text-[10px] font-semibold text-muted-foreground/60 uppercase tracking-widest">
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-teal-500" />
                        Joins
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-full bg-amber-400 dark:bg-amber-500" />
                        Sign-offs
                    </span>
                </div>

                {!hasActivity ? (
                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                        No movements scheduled this week
                    </p>
                ) : (
                    <div className="space-y-3">
                        {days.map((day) => {
                            const isEmpty =
                                day.joins === 0 && day.signoffs === 0;

                            return (
                                <div
                                    key={day.date}
                                    className={cn(
                                        'group flex items-center gap-3 rounded-xl px-3 py-2',
                                        !isEmpty &&
                                            'bg-muted/15 dark:bg-white/2',
                                    )}
                                >
                                    {/* Day label */}
                                    <p
                                        className={cn(
                                            'w-16 shrink-0 text-sm font-semibold',
                                            day.label === 'Today'
                                                ? 'text-primary'
                                                : 'text-foreground/75',
                                        )}
                                    >
                                        {day.label}
                                    </p>

                                    {/* Bar */}
                                    <div className="flex-1">
                                        <ActivityBar
                                            joins={day.joins}
                                            signoffs={day.signoffs}
                                            max={max}
                                        />
                                    </div>

                                    {/* Counts */}
                                    <div className="flex w-24 shrink-0 justify-end gap-3 text-xs tabular-nums">
                                        {day.joins > 0 ? (
                                            <span className="flex items-center gap-1 font-semibold text-teal-600 dark:text-teal-400">
                                                <LogIn className="size-3" />
                                                {day.joins}
                                            </span>
                                        ) : null}
                                        {day.signoffs > 0 ? (
                                            <span className="flex items-center gap-1 font-semibold text-amber-600 dark:text-amber-400">
                                                <LogOut className="size-3" />
                                                {day.signoffs}
                                            </span>
                                        ) : null}
                                        {isEmpty ? (
                                            <span className="text-muted-foreground/40">
                                                —
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
