import { BarChart3, LogIn, LogOut } from 'lucide-react';
import type { ReactElement } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { CrewOperationsDeploymentTrendPoint } from '@/features/organization/crew-operations/types';
import { cn } from '@/lib/utils';

function TrendsSkeleton(): ReactElement {
    return (
        <div className="space-y-3" role="status" aria-label="Loading trends">
            <div className="flex items-end gap-2 px-1 pt-2">
                {[40, 65, 50, 80, 55, 90].map((h, i) => (
                    <Skeleton
                        key={i}
                        className="flex-1 rounded-md"
                        style={{ height: `${h}px` }}
                    />
                ))}
            </div>
        </div>
    );
}

function BarGroup({
    point,
    maxValue,
}: {
    point: CrewOperationsDeploymentTrendPoint;
    maxValue: number;
}): ReactElement {
    const joinHeight =
        maxValue > 0 ? Math.max((point.joins / maxValue) * 100, 2) : 0;
    const disembarkHeight =
        maxValue > 0 ? Math.max((point.disembarks / maxValue) * 100, 2) : 0;

    return (
        <div className="group flex flex-1 flex-col items-center gap-1.5">
            {/* Bars */}
            <div className="flex w-full items-end justify-center gap-0.5">
                {/* Joins bar */}
                <div
                    className="relative w-1/2 overflow-hidden rounded-t-sm bg-teal-500/80 transition-all duration-500 hover:bg-teal-500"
                    style={{ height: `${joinHeight}px` }}
                    title={`Joins: ${point.joins}`}
                />
                {/* Disembarks bar */}
                <div
                    className="relative w-1/2 overflow-hidden rounded-t-sm bg-amber-400/80 transition-all duration-500 hover:bg-amber-400 dark:bg-amber-500/80 dark:hover:bg-amber-500"
                    style={{ height: `${disembarkHeight}px` }}
                    title={`Sign-offs: ${point.disembarks}`}
                />
            </div>

            {/* Month label */}
            <p className="text-[10px] font-semibold text-muted-foreground/60">
                {point.month}
            </p>

            {/* Tooltip on hover */}
            <div className="pointer-events-none absolute -top-14 left-1/2 z-10 -translate-x-1/2 rounded-lg border border-border/60 bg-popover px-2.5 py-1.5 text-[11px] font-medium opacity-0 shadow-lg transition-opacity group-hover:opacity-100 dark:border-white/10">
                <p className="flex items-center gap-1 text-teal-600 dark:text-teal-400">
                    <LogIn className="size-3" />
                    {point.joins} joins
                </p>
                <p className="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                    <LogOut className="size-3" />
                    {point.disembarks} off
                </p>
            </div>
        </div>
    );
}

export function DeploymentTrendsCard({
    trends,
}: {
    trends: CrewOperationsDeploymentTrendPoint[] | undefined;
}): ReactElement {
    const maxValue =
        trends && trends.length > 0
            ? Math.max(...trends.flatMap((t) => [t.joins, t.disembarks]), 1)
            : 1;

    const totalJoins = trends?.reduce((s, t) => s + t.joins, 0) ?? 0;
    const totalDisembarks = trends?.reduce((s, t) => s + t.disembarks, 0) ?? 0;

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-xl border border-border/60 bg-muted/30 dark:border-white/8 dark:bg-white/5">
                            <BarChart3 className="size-4 text-muted-foreground" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-bold tracking-tight">
                                Deployment Trends
                            </CardTitle>
                            <CardDescription className="text-xs">
                                6-month actual joins &amp; sign-offs
                            </CardDescription>
                        </div>
                    </div>

                    {trends ? (
                        <div className="flex items-center gap-4 text-xs">
                            <span className="flex items-center gap-1.5 font-semibold text-teal-600 dark:text-teal-400">
                                <LogIn className="size-3.5" />
                                {totalJoins} total joins
                            </span>
                            <span className="flex items-center gap-1.5 font-semibold text-amber-600 dark:text-amber-400">
                                <LogOut className="size-3.5" />
                                {totalDisembarks} total sign-offs
                            </span>
                        </div>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="pt-5">
                {!trends ? (
                    <TrendsSkeleton />
                ) : (
                    <>
                        {/* Legend */}
                        <div className="mb-4 flex items-center gap-4 text-[10px] font-bold tracking-widest text-muted-foreground/55 uppercase">
                            <span className="flex items-center gap-1.5">
                                <span className="size-2 rounded-sm bg-teal-500" />
                                Joins
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span className="size-2 rounded-sm bg-amber-400 dark:bg-amber-500" />
                                Sign-offs
                            </span>
                        </div>

                        {/* Chart */}
                        <div className="relative flex h-28 items-end gap-2">
                            {/* Y-axis guide lines */}
                            <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                {[0, 1, 2, 3].map((i) => (
                                    <div
                                        key={i}
                                        className={cn(
                                            'w-full border-t border-dashed border-border/30 dark:border-white/5',
                                            i === 3 && 'border-t-0',
                                        )}
                                    />
                                ))}
                            </div>

                            {trends.map((point) => (
                                <BarGroup
                                    key={point.month}
                                    point={point}
                                    maxValue={maxValue}
                                />
                            ))}
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
