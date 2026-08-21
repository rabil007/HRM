import { Link } from '@inertiajs/react';
import { CalendarCheck, CheckCircle2, ShieldAlert } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CrewOperationsProjectedManning } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { index as vesselManningIndex } from '@/routes/organization/vessel-manning';

interface SegmentProps {
    value: number;
    total: number;
    color: string;
    label: string;
}

function Segment({
    value,
    total,
    color,
    label,
}: SegmentProps): ReactElement | null {
    if (value <= 0 || total <= 0) {
        return null;
    }

    const pct = Math.max((value / total) * 100, 2);

    return (
        <div
            className={cn('relative h-full cursor-default rounded-sm', color)}
            style={{ width: `${pct}%` }}
            title={`${label}: ${value}`}
        >
            <span className="sr-only">
                {label}: {value}
            </span>
        </div>
    );
}

export function CoverageHorizonCard({
    projected,
    canViewManning,
}: {
    projected: CrewOperationsProjectedManning;
    canViewManning: boolean;
}): ReactElement {
    const total =
        projected.covered_positions +
        projected.overlap_positions +
        projected.current_gap_positions +
        projected.future_gap_positions;

    const hasCritical = projected.current_gap_positions > 0;
    const hasFutureGaps = projected.future_gap_positions > 0;
    const isHealthy = !hasCritical && !hasFutureGaps;

    const statusIcon = isHealthy ? CheckCircle2 : ShieldAlert;
    const StatusIcon = statusIcon;

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div
                            className={cn(
                                'flex size-9 items-center justify-center rounded-xl border',
                                isHealthy
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-500'
                                    : hasCritical
                                      ? 'border-destructive/30 bg-destructive/10 text-destructive'
                                      : 'border-warning/30 bg-warning/10 text-warning',
                            )}
                        >
                            <StatusIcon className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-bold tracking-tight">
                                Coverage Horizon
                            </CardTitle>
                            <CardDescription className="text-xs">
                                {projected.horizon_days}-day projected manning
                                outlook
                            </CardDescription>
                        </div>
                    </div>

                    {canViewManning ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            asChild
                        >
                            <Link href={vesselManningIndex.url()}>
                                View Manning
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="pt-5">
                {/* Segmented progress bar */}
                <div className="mb-4 flex h-3 w-full gap-px overflow-hidden rounded-full bg-muted/20 dark:bg-white/5">
                    <Segment
                        value={projected.covered_positions}
                        total={total}
                        color="bg-emerald-500/70"
                        label="Covered"
                    />
                    <Segment
                        value={projected.overlap_positions}
                        total={total}
                        color="bg-teal-400/70"
                        label="Overlap"
                    />
                    <Segment
                        value={projected.future_gap_positions}
                        total={total}
                        color="bg-warning/70"
                        label="Future gap"
                    />
                    <Segment
                        value={projected.current_gap_positions}
                        total={total}
                        color="bg-destructive/70"
                        label="Gap now"
                    />
                </div>

                {/* Legend + stats */}
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <StatPill
                        label="Covered"
                        value={projected.covered_positions}
                        dot="bg-emerald-500/70"
                    />
                    <StatPill
                        label="Overlap"
                        value={projected.overlap_positions}
                        dot="bg-teal-400/70"
                    />
                    <StatPill
                        label="Future gap"
                        value={projected.future_gap_positions}
                        dot="bg-warning/70"
                        tone={hasFutureGaps ? 'warning' : undefined}
                    />
                    <StatPill
                        label="Gap now"
                        value={projected.current_gap_positions}
                        dot="bg-destructive/70"
                        tone={hasCritical ? 'danger' : undefined}
                    />
                </div>

                {/* Next gap / shortfall summary */}
                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground/70">
                    {projected.next_gap_date ? (
                        <span className="flex items-center gap-1.5">
                            <CalendarCheck className="size-3.5" />
                            Next gap:{' '}
                            <span className="font-semibold text-foreground/80">
                                {formatDisplayDate(projected.next_gap_date)}
                            </span>
                        </span>
                    ) : (
                        <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                            <CheckCircle2 className="size-3.5" />
                            No gaps in the next {projected.horizon_days} days
                        </span>
                    )}
                    {projected.projected_shortfall_days > 0 ? (
                        <span>
                            Shortfall:{' '}
                            <span className="font-semibold text-warning">
                                {projected.projected_shortfall_days} days
                            </span>
                        </span>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function StatPill({
    label,
    value,
    dot,
    tone,
}: {
    label: string;
    value: number;
    dot: string;
    tone?: 'danger' | 'warning';
}): ReactElement {
    return (
        <div className="flex flex-col rounded-xl border border-border/50 bg-muted/10 p-2.5 dark:border-white/5 dark:bg-white/2">
            <div className="flex items-center gap-1.5">
                <span className={cn('size-2 rounded-full', dot)} />
                <span className="text-[10px] font-medium text-muted-foreground/70">
                    {label}
                </span>
            </div>
            <p
                className={cn(
                    'mt-1 text-xl font-extrabold tabular-nums',
                    tone === 'danger' && 'text-destructive',
                    tone === 'warning' && 'text-warning',
                    !tone && 'text-foreground/80',
                )}
            >
                {value}
            </p>
        </div>
    );
}
