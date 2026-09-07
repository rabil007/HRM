import { Link } from '@inertiajs/react';
import { ShieldCheck, Users } from 'lucide-react';
import {
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { index as crewAssignmentsIndex } from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import {
    vesselManningHealthBadgeClass,
    vesselManningHealthDot,
} from '../lib/vessel-manning-health';
import type {
    VesselManningHealth,
    VesselManningHealthRank,
    VesselPageCan,
} from '../types';

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <div className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                {label}
            </div>
            <div className="text-lg font-extrabold tracking-tight text-foreground tabular-nums">
                {value}
            </div>
        </div>
    );
}

function RankCard({ rank }: { rank: VesselManningHealthRank }) {
    const primaryRelief = rank.reliefs[0];

    return (
        <div className="rounded-xl border border-border/70 bg-card/60 p-4 dark:border-white/8 dark:bg-white/[0.03]">
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-semibold">{rank.rank_name}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {rank.reason}
                    </p>
                </div>
                <Badge
                    variant="outline"
                    className={cn(
                        'shrink-0 text-[10px] font-bold tracking-wider uppercase',
                        vesselManningHealthBadgeClass(rank.status),
                    )}
                >
                    {vesselManningHealthDot(rank.status)} {rank.status_label}
                </Badge>
            </div>
            <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                        Required
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {rank.required}
                    </dd>
                </div>
                <div>
                    <dt className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                        Onboard
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {rank.onboard}
                    </dd>
                </div>
                <div>
                    <dt className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                        Projected
                    </dt>
                    <dd className="font-semibold tabular-nums">
                        {rank.projected}
                    </dd>
                </div>
                <div>
                    <dt className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                        Gap from
                    </dt>
                    <dd className="font-semibold">
                        {rank.next_gap_date
                            ? formatDisplayDate(rank.next_gap_date)
                            : '—'}
                    </dd>
                </div>
            </dl>
            <div className="mt-3 border-t border-border/60 pt-3 text-xs dark:border-white/8">
                <p className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                    Relief
                </p>
                <p className="mt-1 font-medium">
                    {primaryRelief?.relief_employee_name
                        ? `${primaryRelief.relief_employee_name}${
                              primaryRelief.relief_phase_label
                                  ? ` · ${primaryRelief.relief_phase_label}`
                                  : ` · ${primaryRelief.relief_status_label}`
                          }`
                        : (rank.relief_status_label ?? rank.relief_summary)}
                </p>
                {rank.signoffs[0]?.employee_name ? (
                    <p className="mt-1 text-muted-foreground">
                        {rank.signoffs[0].employee_name}
                        {rank.signoffs[0].planned_signoff_at
                            ? ` · Planned Sign-Off ${formatDisplayDate(rank.signoffs[0].planned_signoff_at)}`
                            : null}
                    </p>
                ) : null}
                {rank.mobilisation_readiness_label ? (
                    <p className="mt-1 text-muted-foreground">
                        Mobilisation: {rank.mobilisation_readiness_label}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

export function VesselManningHealthCard({
    vesselId,
    health,
    can,
    canEditManning,
    onEditManning,
}: {
    vesselId: number;
    health: VesselManningHealth;
    can: VesselPageCan;
    canEditManning: boolean;
    onEditManning?: () => void;
}) {
    const reliefDeskHref = crewPlanningIndex.url({
        query: { view: 'relief', vessel_id: vesselId },
    });
    const planningHref = crewPlanningIndex.url({
        query: { vessel_id: vesselId },
    });
    const currentCrewHref = crewAssignmentsIndex.url({
        query: { view: 'vessel', vessel_id: vesselId },
    });

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
            <CardHeader className="border-b border-border pb-4 dark:border-white/5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-base font-bold">
                            Manning Health
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            Looking ahead {health.horizon_days} days
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        className={cn(
                            'w-fit text-[10px] font-bold tracking-wider uppercase',
                            vesselManningHealthBadgeClass(health.status),
                        )}
                    >
                        {vesselManningHealthDot(health.status)}{' '}
                        {health.status_label}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-6 pt-6">
                {health.status === 'not_configured' ? (
                    <div className="flex flex-col items-center gap-3 px-2 py-6 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/5">
                            <ShieldCheck className="size-5 text-muted-foreground/60" />
                        </div>
                        <p className="text-sm font-semibold">
                            Manning Not Configured
                        </p>
                        <p className="max-w-md text-xs text-muted-foreground">
                            {health.reason}
                        </p>
                        {canEditManning && onEditManning ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onEditManning}
                            >
                                Configure Manning
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {health.reason}
                        </p>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                            <Metric
                                label="Required"
                                value={String(health.required)}
                            />
                            <Metric
                                label="Onboard"
                                value={String(health.onboard)}
                            />
                            <Metric
                                label="Current Gap"
                                value={String(health.current_gap)}
                            />
                            <Metric
                                label="Future Gap"
                                value={
                                    health.future_gap > 0
                                        ? `${health.future_gap} positions`
                                        : '0'
                                }
                            />
                            <Metric
                                label="Next Gap"
                                value={
                                    health.next_gap_date
                                        ? formatDisplayDate(
                                              health.next_gap_date,
                                          )
                                        : '—'
                                }
                            />
                        </div>
                        {health.signing_off_within_14_days > 0 ||
                        health.ready_reliefs > 0 ||
                        health.projected_shortfall_days > 0 ||
                        health.overlap_excess > 0 ? (
                            <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                                {health.signing_off_within_14_days > 0 ? (
                                    <span>
                                        Signing Off ≤14 Days{' '}
                                        {health.signing_off_within_14_days}
                                    </span>
                                ) : null}
                                {health.ready_reliefs > 0 ? (
                                    <span>
                                        Ready Reliefs {health.ready_reliefs}
                                    </span>
                                ) : null}
                                {health.projected_shortfall_days > 0 ? (
                                    <span>
                                        Projected Shortfall{' '}
                                        {health.projected_shortfall_days}{' '}
                                        crew-days
                                    </span>
                                ) : null}
                                {health.overlap_excess > 0 ? (
                                    <span>
                                        +{health.overlap_excess} temporary
                                        overlap
                                    </span>
                                ) : null}
                            </div>
                        ) : null}

                        <div>
                            <h3 className="mb-3 text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                Rank Manning Health
                            </h3>
                            <div className="space-y-3 md:hidden">
                                {health.ranks.map((rank) => (
                                    <RankCard key={rank.rank_id} rank={rank} />
                                ))}
                            </div>
                            <div className="hidden overflow-x-auto md:block">
                                <Table className="min-w-[720px]">
                                    <TableHeader>
                                        <DataTableHeaderRow>
                                            <DataTableHead>Rank</DataTableHead>
                                            <DataTableHead className="text-right">
                                                Required
                                            </DataTableHead>
                                            <DataTableHead className="text-right">
                                                Onboard
                                            </DataTableHead>
                                            <DataTableHead>
                                                Planned / Relief
                                            </DataTableHead>
                                            <DataTableHead className="text-right">
                                                Projected
                                            </DataTableHead>
                                            <DataTableHead>
                                                Health
                                            </DataTableHead>
                                        </DataTableHeaderRow>
                                    </TableHeader>
                                    <TableBody>
                                        {health.ranks.map((rank) => (
                                            <TableRow
                                                key={rank.rank_id}
                                                className={dataTableBodyRowClass(
                                                    false,
                                                )}
                                            >
                                                <TableCell
                                                    className={dataTableCellClass()}
                                                >
                                                    <div className="min-w-0">
                                                        <p className="font-semibold">
                                                            {rank.rank_name}
                                                        </p>
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {rank.reason}
                                                        </p>
                                                        {health.include_crew_details &&
                                                        rank.signoffs[0]
                                                            ?.employee_name ? (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {
                                                                    rank
                                                                        .signoffs[0]
                                                                        .employee_name
                                                                }
                                                                {rank
                                                                    .signoffs[0]
                                                                    .planned_signoff_at
                                                                    ? ` · Planned Sign-Off ${formatDisplayDate(rank.signoffs[0].planned_signoff_at)}`
                                                                    : null}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        dataTableCellClass(),
                                                        'text-right tabular-nums',
                                                    )}
                                                >
                                                    {rank.required}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        dataTableCellClass(),
                                                        'text-right tabular-nums',
                                                    )}
                                                >
                                                    {rank.onboard}
                                                </TableCell>
                                                <TableCell
                                                    className={dataTableCellClass()}
                                                >
                                                    <span className="text-sm">
                                                        {rank.relief_summary}
                                                    </span>
                                                    {rank.mobilisation_readiness_label ? (
                                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                                            {
                                                                rank.mobilisation_readiness_label
                                                            }
                                                        </span>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        dataTableCellClass(),
                                                        'text-right tabular-nums',
                                                    )}
                                                >
                                                    {rank.projected}
                                                </TableCell>
                                                <TableCell
                                                    className={dataTableCellClass()}
                                                >
                                                    <Badge
                                                        variant="outline"
                                                        className={cn(
                                                            'text-[10px] font-bold tracking-wider uppercase',
                                                            vesselManningHealthBadgeClass(
                                                                rank.status,
                                                            ),
                                                        )}
                                                    >
                                                        {vesselManningHealthDot(
                                                            rank.status,
                                                        )}{' '}
                                                        {rank.status_label}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </>
                )}

                <div className="flex flex-wrap gap-2 border-t border-border/60 pt-4 dark:border-white/8">
                    {can.view_planning ? (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={reliefDeskHref}>
                                    Open Relief Desk
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={planningHref}>
                                    Open Crew Planning
                                </Link>
                            </Button>
                        </>
                    ) : null}
                    {can.view_assignments ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={currentCrewHref}>
                                <Users className="mr-2 h-4 w-4" />
                                View Current Crew
                            </Link>
                        </Button>
                    ) : null}
                    {canEditManning && onEditManning ? (
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={onEditManning}
                        >
                            Edit Manning
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
