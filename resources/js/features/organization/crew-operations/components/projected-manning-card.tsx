import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { projectedManningStatusVariant } from '@/features/organization/crew-operations/projected-manning/status';
import type { ProjectedManningStatus } from '@/features/organization/crew-operations/projected-manning/types';
import type { CrewOperationsProjectedManning } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { projectedManning as projectedManningRoute } from '@/routes/organization/crew-operations';

function Metric({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'danger' | 'warning' | 'muted';
}): ReactElement {
    return (
        <div className="rounded-xl border border-border/70 bg-muted/10 px-3 py-2 dark:border-white/5 dark:bg-white/1">
            <p className="text-[10px] font-bold tracking-[0.14em] text-muted-foreground/65 uppercase">
                {label}
            </p>
            <p
                className={
                    tone === 'danger'
                        ? 'mt-0.5 text-lg font-bold text-destructive tabular-nums'
                        : tone === 'warning'
                          ? 'mt-0.5 text-lg font-bold text-warning tabular-nums'
                          : 'mt-0.5 text-lg font-bold text-foreground/85 tabular-nums'
                }
            >
                {value}
            </p>
        </div>
    );
}

export function ProjectedManningCard({
    projectedManning,
}: {
    projectedManning: CrewOperationsProjectedManning;
}): ReactElement {
    const gapCount =
        projectedManning.current_gap_positions +
        projectedManning.future_gap_positions;

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/2">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">
                            Projected Manning — Next{' '}
                            {projectedManning.horizon_days} Days
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Forecast coverage from{' '}
                            {formatDisplayDate(projectedManning.from)} to{' '}
                            {formatDisplayDate(projectedManning.to)}. Separate
                            from actual onboard staffing.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {gapCount > 0 ? (
                            <Badge variant="warning" className="tabular-nums">
                                {gapCount} projected gaps
                            </Badge>
                        ) : null}
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg text-xs"
                            asChild
                        >
                            <Link href={projectedManningRoute.url()}>
                                View Projected Manning
                            </Link>
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4 pt-4">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <Metric
                        label="Current gaps"
                        value={projectedManning.current_gap_positions}
                        tone={
                            projectedManning.current_gap_positions > 0
                                ? 'danger'
                                : 'muted'
                        }
                    />
                    <Metric
                        label="Future gaps"
                        value={projectedManning.future_gap_positions}
                        tone={
                            projectedManning.future_gap_positions > 0
                                ? 'warning'
                                : 'muted'
                        }
                    />
                    <Metric
                        label="Covered"
                        value={projectedManning.covered_positions}
                    />
                    <Metric
                        label="Shortfall days"
                        value={projectedManning.projected_shortfall_days}
                        tone={
                            projectedManning.projected_shortfall_days > 0
                                ? 'warning'
                                : 'muted'
                        }
                    />
                    <Metric
                        label="Overlap"
                        value={projectedManning.overlap_positions}
                    />
                </div>

                {projectedManning.next_gap_date ? (
                    <p className="text-xs text-muted-foreground">
                        Nearest projected gap:{' '}
                        <span className="font-semibold text-foreground/80">
                            {formatDisplayDate(projectedManning.next_gap_date)}
                        </span>
                    </p>
                ) : (
                    <p className="text-xs text-muted-foreground/70">
                        No projected gaps in this horizon.
                    </p>
                )}

                {projectedManning.critical_positions.length === 0 ? (
                    <p className="py-4 text-center text-sm text-muted-foreground/50">
                        No current or future projected gaps to highlight
                    </p>
                ) : (
                    <div className="space-y-2">
                        {projectedManning.critical_positions.map((item) => (
                            <Link
                                key={`${item.vessel_id}-${item.rank_id}`}
                                href={projectedManningRoute.url({
                                    query: {
                                        vessel_id: item.vessel_id,
                                        rank_id: item.rank_id,
                                    },
                                })}
                                className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/1 dark:hover:border-white/10"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                            {item.vessel_name}
                                        </p>
                                        <Badge
                                            variant={projectedManningStatusVariant(
                                                item.status as ProjectedManningStatus,
                                            )}
                                        >
                                            {item.status_label}
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground/60">
                                        {item.rank_name}
                                    </p>
                                    <p className="mt-1 text-[11px] text-muted-foreground/50">
                                        Required {item.required_count} · Min
                                        projected {item.minimum_projected_count}{' '}
                                        · Max gap {item.maximum_gap}
                                        {item.next_gap_date
                                            ? ` · Next gap ${formatDisplayDate(item.next_gap_date)}`
                                            : ''}
                                    </p>
                                </div>
                                <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all group-hover:opacity-100" />
                            </Link>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
