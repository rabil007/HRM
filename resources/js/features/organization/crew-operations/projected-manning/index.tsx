import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Anchor,
    CalendarRange,
    CheckCircle2,
    Filter,
    Layers3,
    Ship,
    TrendingDown,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDisplayDate } from '@/lib/format-date';
import { projectedManning as projectedManningRoute } from '@/routes/organization/crew-operations';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { ProjectedManningPositionRow } from './components/projected-manning-position-row';
import { ProjectedManningSummaryCards } from './components/projected-manning-summary-cards';
import type { ProjectedManningPageProps } from './types';

function visitFilters(next: {
    horizon?: number;
    vessel_id?: number | null;
    rank_id?: number | null;
}): void {
    const query: Record<string, string> = {};

    if (next.horizon !== undefined && next.horizon !== 30) {
        query.horizon = String(next.horizon);
    }

    if (next.vessel_id) {
        query.vessel_id = String(next.vessel_id);
    }

    if (next.rank_id) {
        query.rank_id = String(next.rank_id);
    }

    router.get(projectedManningRoute.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

export function ProjectedManningContent({
    from,
    to,
    company_timezone: companyTimezone,
    summary,
    items,
    filters,
    horizons,
    vessels,
    ranks,
    can,
}: ProjectedManningPageProps): ReactElement {
    const hasActiveFilters =
        filters.horizon !== 30 ||
        filters.vessel_id !== null ||
        filters.rank_id !== null;

    const sortedItems = useMemo(
        () =>
            [...items].sort((a, b) => {
                const vesselCmp = a.vessel_name.localeCompare(b.vessel_name);

                if (vesselCmp !== 0) {
                    return vesselCmp;
                }

                return a.rank_name.localeCompare(b.rank_name);
            }),
        [items],
    );

    return (
        <Main className="flex flex-1 flex-col gap-6">
            <PageHeader
                title="Projected Manning"
                description={`Forecast vessel and rank coverage from ${formatDisplayDate(from)} to ${formatDisplayDate(to)} (${companyTimezone}). Actual onboard is operational truth; projected coverage includes planned joins and sign-offs.`}
                right={
                    can.plan_crew ? (
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-xl"
                        >
                            <Link href={crewPlanningIndex.url()}>
                                <CalendarRange className="size-4" />
                                Open Crew Planning
                            </Link>
                        </Button>
                    ) : null
                }
            />

            <ProjectedManningSummaryCards summary={summary} />

            <Card className="glass-card border-border/60 dark:border-white/5">
                <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                    <div className="space-y-1.5">
                        <p className="text-[10px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                            Horizon
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {horizons.map((days) => (
                                <Button
                                    key={days}
                                    type="button"
                                    size="sm"
                                    variant={
                                        filters.horizon === days
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="rounded-lg"
                                    onClick={() =>
                                        visitFilters({
                                            horizon: days,
                                            vessel_id: filters.vessel_id,
                                            rank_id: filters.rank_id,
                                        })
                                    }
                                >
                                    {days} days
                                </Button>
                            ))}
                        </div>
                    </div>

                    <div className="min-w-[11rem] flex-1 space-y-1.5">
                        <p className="text-[10px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                            Vessel
                        </p>
                        <AppSelect
                            value={
                                filters.vessel_id
                                    ? String(filters.vessel_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                visitFilters({
                                    horizon: filters.horizon,
                                    vessel_id:
                                        value === '' ? null : Number(value),
                                    rank_id: filters.rank_id,
                                })
                            }
                            placeholder="All vessels"
                        >
                            <AppSelectItem value="">All vessels</AppSelectItem>
                            {vessels.map((vessel) => (
                                <AppSelectItem
                                    key={vessel.id}
                                    value={String(vessel.id)}
                                >
                                    {vessel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="min-w-[11rem] flex-1 space-y-1.5">
                        <p className="text-[10px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                            Rank
                        </p>
                        <AppSelect
                            value={
                                filters.rank_id ? String(filters.rank_id) : ''
                            }
                            onValueChange={(value) =>
                                visitFilters({
                                    horizon: filters.horizon,
                                    vessel_id: filters.vessel_id,
                                    rank_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            placeholder="All ranks"
                        >
                            <AppSelectItem value="">All ranks</AppSelectItem>
                            {ranks.map((rank) => (
                                <AppSelectItem
                                    key={rank.id}
                                    value={String(rank.id)}
                                >
                                    {rank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {hasActiveFilters ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="rounded-lg"
                            onClick={() =>
                                visitFilters({
                                    horizon: 30,
                                    vessel_id: null,
                                    rank_id: null,
                                })
                            }
                        >
                            <Filter className="size-3.5" />
                            Reset filters
                        </Button>
                    ) : null}
                </CardContent>
            </Card>

            {sortedItems.length === 0 ? (
                <EmptyState
                    icon={
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Ship className="h-6 w-6 text-muted-foreground" />
                        </div>
                    }
                    title="No manning positions to project"
                    description="Configure vessel manning requirements first, or clear vessel/rank filters to see all projected positions."
                />
            ) : (
                <div className="space-y-3">
                    <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1.5">
                            <Anchor className="size-3.5" />
                            Actual onboard = current P4 operational truth
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <Layers3 className="size-3.5" />
                            Projected coverage = forecast including planned
                            joins/sign-offs
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <CheckCircle2 className="size-3.5 text-success" />
                            Covered
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <TrendingDown className="size-3.5 text-warning" />
                            Future gap
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <AlertTriangle className="size-3.5 text-destructive" />
                            Current gap
                        </span>
                    </div>

                    <div className="space-y-2">
                        {sortedItems.map((item) => (
                            <ProjectedManningPositionRow
                                key={`${item.vessel_id}-${item.rank_id}`}
                                item={item}
                                from={from}
                                to={to}
                                canPlanCrew={can.plan_crew}
                            />
                        ))}
                    </div>
                </div>
            )}
        </Main>
    );
}
