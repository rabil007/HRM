import { CalendarDays } from 'lucide-react';
import type { ReactElement } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CrewAssignmentDetail } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

interface PlanVsActualRow {
    label: string;
    planned: string | null | undefined;
    actual: string | null | undefined;
}

export function CrewAssignmentPlanVsActual({
    assignment,
}: {
    assignment: Pick<
        CrewAssignmentDetail,
        | 'planned_arrival_at'
        | 'actual_arrival_at'
        | 'planned_join_at'
        | 'actual_join_at'
        | 'planned_travel_at'
        | 'planned_signoff_at'
        | 'actual_disembarkation_at'
        | 'started_at'
        | 'closed_at'
    >;
}): ReactElement {
    const rows: PlanVsActualRow[] = [
        {
            label: 'Arrival',
            planned: assignment.planned_arrival_at,
            actual: assignment.actual_arrival_at,
        },
        {
            label: 'Vessel Join',
            planned: assignment.planned_join_at,
            actual: assignment.actual_join_at,
        },
        {
            label: 'Travel',
            planned: assignment.planned_travel_at,
            actual: null,
        },
        {
            label: 'Sign-Off',
            planned: assignment.planned_signoff_at,
            actual: assignment.actual_disembarkation_at,
        },
        {
            label: 'Started',
            planned: null,
            actual: assignment.started_at,
        },
        {
            label: 'Closed',
            planned: null,
            actual: assignment.closed_at,
        },
    ];

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                <div className="flex items-center gap-2">
                    <CalendarDays className="size-3.5 text-muted-foreground" />
                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        Plan vs Actual
                    </CardTitle>
                </div>
                {/* Column headers */}
                <div className="mt-2 grid grid-cols-[1fr_auto_auto] gap-x-3 px-1">
                    <span />
                    <span className="text-[10px] font-bold tracking-wide text-muted-foreground/60 uppercase">
                        Planned
                    </span>
                    <span className="text-[10px] font-bold tracking-wide text-primary/70 uppercase">
                        Actual
                    </span>
                </div>
            </CardHeader>
            <CardContent className="px-2 pt-2 pb-1">
                {rows.map((row) => {
                    const hasVariance =
                        row.planned && row.actual && row.planned !== row.actual;

                    return (
                        <div
                            key={row.label}
                            className="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 border-b border-border/30 px-1 py-2 last:border-b-0"
                        >
                            <span className="text-[11px] font-medium text-muted-foreground">
                                {row.label}
                            </span>
                            <span className="text-right text-[11px] text-muted-foreground/70">
                                {formatDisplayDate(row.planned) ?? '—'}
                            </span>
                            <span
                                className={cn(
                                    'text-right text-[11px] font-medium',
                                    hasVariance
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : row.actual
                                          ? 'text-foreground'
                                          : 'text-muted-foreground/40',
                                )}
                            >
                                {formatDisplayDate(row.actual) ?? '—'}
                            </span>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
