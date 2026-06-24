import { useForm } from '@inertiajs/react';
import { Anchor, Pencil, Ship, Users, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    index as vesselManningIndex,
    update as updateVesselManning,
} from '@/actions/App/Http/Controllers/Organization/VesselManningController';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard  } from '@/components/recent-activity-card';
import type {RecentActivityItem} from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { actions } from '@/lib/design-system';
import { formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { VesselManningFormSheet } from './components/vessel-manning-form-sheet';
import { vesselManningHasWriteActions } from './types';
import type {
    RankOption,
    VesselManningFormData,
    VesselManningPagePermissions,
    VesselManningShowItem,
} from './types';
import { toVesselManningFormData, toVesselManningPayload } from './vessel-manning-form-utils';

function StatChip({
    label,
    value,
    icon: Icon,
    highlight = false,
}: {
    label: string;
    value: string;
    icon?: any;
    highlight?: boolean;
}) {
    return (
        <div
            className={cn(
                'group relative overflow-hidden rounded-xl border p-4 transition-all duration-300 hover:shadow-xs',
                highlight
                    ? 'border-primary/20 bg-primary/5 dark:bg-primary/[0.02]'
                    : 'border-border/80 bg-muted/20 dark:border-white/10 dark:bg-white/3',
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="space-y-1">
                    <div className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">
                        {label}
                    </div>
                    <div className="text-xl font-extrabold tracking-tight tabular-nums text-foreground">{value}</div>
                </div>
                {Icon ? (
                    <div className={cn(
                        "flex size-9 items-center justify-center rounded-lg border",
                        highlight 
                            ? "border-primary/20 bg-primary/10 text-primary" 
                            : "border-border/60 bg-muted/40 text-muted-foreground dark:border-white/8 dark:bg-white/5"
                    )}>
                        <Icon className="size-4" />
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export function VesselManningShowContent({
    vessel,
    recent_activity,
    can_view_audit,
    can,
    ranks,
    back_query,
}: {
    vessel: VesselManningShowItem;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    can: VesselManningPagePermissions;
    ranks: RankOption[];
    back_query: Record<string, string>;
}) {
    const [editOpen, setEditOpen] = useState(false);

    const backHref = useMemo(
        () => vesselManningIndex.url(Object.keys(back_query).length > 0 ? { query: back_query } : undefined),
        [back_query],
    );

    const form = useForm<VesselManningFormData>(toVesselManningFormData(vessel));

    const openEdit = (): void => {
        form.clearErrors();
        form.setData(toVesselManningFormData(vessel));
        setEditOpen(true);
    };

    const submit = (): void => {
        form.transform((data) => ({
            ...toVesselManningPayload(data),
            redirect_to: 'show' as const,
        }));
        form.put(
            updateVesselManning.url(
                { vessel: vessel.id },
                Object.keys(back_query).length > 0 ? { query: back_query } : undefined,
            ),
            {
                preserveScroll: true,
                onSuccess: () => setEditOpen(false),
            },
        );
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Crew Operations"
                title={vessel.name}
                description={`${formatDisplayValue(vessel.vessel_type_name)} · ${vessel.is_active ? 'Active vessel' : 'Inactive vessel'}`}
                backHref={backHref}
                backLabel="Back to vessel manning"
                actions={
                    vesselManningHasWriteActions(can) ? (
                        <Button type="button" className={actions.primary} onClick={openEdit}>
                            <Pencil className="mr-2 h-4 w-4" />
                            Edit manning
                        </Button>
                    ) : null
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="glass-card overflow-hidden lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="border-b border-border pb-5 dark:border-white/5">
                        <div className="flex items-start gap-4">
                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                <Ship className="h-7 w-7" />
                            </div>
                            <div className="min-w-0 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={vessel.is_active ? 'default' : 'secondary'}>
                                        {vessel.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                    {vessel.vessel_type_name ? (
                                        <Badge variant="outline">{vessel.vessel_type_name}</Badge>
                                    ) : null}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Required crew mix configured for this vessel in your company.
                                </p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-6 space-y-6">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <StatChip
                                label="Ranks configured"
                                value={String(vessel.ranks_configured)}
                                icon={ShieldCheck}
                            />
                            <StatChip
                                label="Total required"
                                value={vessel.total_required > 0 ? String(vessel.total_required) : '—'}
                                highlight={vessel.total_required > 0}
                                icon={Users}
                            />
                            <StatChip
                                label="Vessel type"
                                value={formatDisplayValue(vessel.vessel_type_name)}
                                icon={Ship}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/10 px-5 py-3.5 dark:border-white/10 dark:bg-white/1">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/75">
                                    GRT (Gross Tonnage)
                                </div>
                                <div className="text-sm font-bold text-foreground">{formatDisplayValue(vessel.grt)}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/10 px-5 py-3.5 dark:border-white/10 dark:bg-white/1">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/75">
                                    BHP (Engine Power)
                                </div>
                                <div className="text-sm font-bold text-foreground">
                                    {vessel.bhp !== null && vessel.bhp !== undefined
                                        ? `${vessel.bhp} HP`
                                        : '—'}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="border-b border-border pb-4 dark:border-white/5">
                        <CardTitle className="text-base font-bold">Summary</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex items-start gap-3 rounded-xl border border-border/60 bg-muted/20 p-4 dark:border-white/10 dark:bg-white/3">
                            <Anchor className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                            <div className="text-sm text-muted-foreground">
                                Vessel and rank master data are managed in Settings. This page only
                                defines how many crew of each rank this vessel needs.
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6 glass-card dark:border-white/5 dark:bg-white/5">
                <CardHeader className="border-b border-border pb-4 dark:border-white/5">
                    <CardTitle className="text-base font-bold">Rank requirements</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    {vessel.manning.length === 0 ? (
                        <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                            No ranks configured yet.
                            {vesselManningHasWriteActions(can) ? (
                                <>
                                    {' '}
                                    <button
                                        type="button"
                                        className="font-medium text-primary underline-offset-4 hover:underline"
                                        onClick={openEdit}
                                    >
                                        Add manning
                                    </button>
                                </>
                            ) : null}
                        </div>
                    ) : (
                        <Table className="min-w-[640px]">
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Rank</DataTableHead>
                                    <DataTableHead>Required</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {vessel.manning.map((line) => (
                                    <TableRow key={line.id} className={dataTableBodyRowClass(false)}>
                                        <TableCell className={dataTableCellClass()}>
                                            <span className="font-semibold text-foreground/80">{line.rank_name}</span>
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <Badge variant="outline" className="border-primary/20 bg-primary/5 text-foreground font-semibold px-3 py-1">
                                                <Users className="mr-1.5 h-3 w-3 text-primary inline" />
                                                {line.required_count}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {can_view_audit ? (
                <RecentActivityCard
                    items={recent_activity}
                    description="Manning requirement changes for this vessel."
                />
            ) : null}

            {vesselManningHasWriteActions(can) ? (
                <VesselManningFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    vessel={vessel}
                    ranks={ranks}
                    form={form}
                    onSubmit={submit}
                />
            ) : null}
        </Main>
    );
}
