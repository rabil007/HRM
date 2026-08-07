import { Link } from '@inertiajs/react';
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
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export function NextSevenDaysCard({
    days,
    canViewPlanning,
}: {
    days: CrewOperationsNextDay[];
    canViewPlanning: boolean;
}): ReactElement {
    const hasActivity = days.some((day) => day.joins > 0 || day.signoffs > 0);

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Next 7 Days
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Planned joins and sign-offs
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
                                Open Crew Planning
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-1 pt-4">
                {!hasActivity ? (
                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                        No joins or sign-offs scheduled this week
                    </p>
                ) : (
                    days.map((day) => {
                        const parts: string[] = [];

                        if (day.joins > 0) {
                            parts.push(
                                `${day.joins} join${day.joins === 1 ? '' : 's'}`,
                            );
                        }

                        if (day.signoffs > 0) {
                            parts.push(
                                `${day.signoffs} sign-off${day.signoffs === 1 ? '' : 's'}`,
                            );
                        }

                        return (
                            <div
                                key={day.date}
                                className="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 even:bg-muted/20 dark:even:bg-white/1"
                            >
                                <p className="w-24 shrink-0 text-sm font-semibold text-foreground/80">
                                    {day.label}
                                </p>
                                <p className="min-w-0 flex-1 text-right text-xs text-muted-foreground/70">
                                    {parts.length > 0 ? parts.join(' · ') : '—'}
                                </p>
                            </div>
                        );
                    })
                )}
            </CardContent>
        </Card>
    );
}
