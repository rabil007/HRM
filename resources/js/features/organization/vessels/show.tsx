import { useForm } from '@inertiajs/react';
import {
    Anchor,
    FileText,
    Pencil,
    Ship,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    index as vesselsIndex,
    update as updateVessel,
    updateManning as updateVesselManning,
} from '@/actions/App/Http/Controllers/Organization/VesselController';
import {
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
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
import { actions } from '@/lib/design-system';
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { VesselManningFormSheet } from '../vessel-manning/components/vessel-manning-form-sheet';
import type {
    RankOption,
    VesselManningFormData,
    VesselManningPagePermissions,
} from '../vessel-manning/types';
import { vesselManningHasWriteActions } from '../vessel-manning/types';
import {
    toVesselManningFormData,
    toVesselManningPayload,
} from '../vessel-manning/vessel-manning-form-utils';
import { VesselFormSheet } from './components/vessel-form-sheet';
import type {
    VesselDetails,
    VesselFormData,
    VesselPageCan,
    VesselSummary,
    VesselTypeOption,
} from './types';

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                {label}
            </div>
            <div className="text-right text-sm font-medium">{value}</div>
        </div>
    );
}

function StatChip({
    label,
    value,
    icon: Icon,
    highlight = false,
}: {
    label: string;
    value: string;
    icon?: React.ComponentType<{ className?: string }>;
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
                    <div className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        {label}
                    </div>
                    <div className="text-xl font-extrabold tracking-tight text-foreground tabular-nums">
                        {value}
                    </div>
                </div>
                {Icon ? (
                    <div
                        className={cn(
                            'flex size-9 items-center justify-center rounded-lg border',
                            highlight
                                ? 'border-primary/20 bg-primary/10 text-primary'
                                : 'border-border/60 bg-muted/40 text-muted-foreground dark:border-white/8 dark:bg-white/5',
                        )}
                    >
                        <Icon className="size-4" />
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export function VesselShowContent({
    vessel,
    vessel_types,
    summary,
    can,
    recent_activity,
    can_view_audit,
    back_query,
    ranks,
    manning_can,
}: {
    vessel: VesselDetails;
    vessel_types: VesselTypeOption[];
    summary: VesselSummary;
    can: VesselPageCan;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    back_query?: Record<string, string>;
    ranks?: RankOption[];
    manning_can?: VesselManningPagePermissions;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [manningEditOpen, setManningEditOpen] = useState(false);

    const backHref = useMemo(
        () =>
            vesselsIndex.url(
                back_query && Object.keys(back_query).length > 0
                    ? { query: back_query }
                    : undefined,
            ),
        [back_query],
    );

    const vesselForm = useForm<VesselFormData>({
        name: vessel.name,
        vessel_type_id: vessel.vessel_type_id as number | '',
        grt:
            vessel.grt !== null && vessel.grt !== undefined
                ? String(vessel.grt)
                : '',
        bhp:
            vessel.bhp !== null && vessel.bhp !== undefined
                ? String(vessel.bhp)
                : '',
        official_no: vessel.official_no ?? '',
        call_sign: vessel.call_sign ?? '',
        imo_no: vessel.imo_no ?? '',
        certificate: null,
        is_active: vessel.is_active,
    });

    const openEdit = (): void => {
        vesselForm.clearErrors();
        vesselForm.setData({
            name: vessel.name,
            vessel_type_id: vessel.vessel_type_id,
            grt:
                vessel.grt !== null && vessel.grt !== undefined
                    ? String(vessel.grt)
                    : '',
            bhp:
                vessel.bhp !== null && vessel.bhp !== undefined
                    ? String(vessel.bhp)
                    : '',
            official_no: vessel.official_no ?? '',
            call_sign: vessel.call_sign ?? '',
            imo_no: vessel.imo_no ?? '',
            certificate: null,
            is_active: vessel.is_active,
        });
        setEditOpen(true);
    };

    const submitVesselEdit = (): void => {
        const hasCertificate = vesselForm.data.certificate instanceof File;

        vesselForm.transform(() => ({
            name: vesselForm.data.name,
            vessel_type_id: vesselForm.data.vessel_type_id,
            grt:
                vesselForm.data.grt.trim() === ''
                    ? null
                    : Number(vesselForm.data.grt),
            bhp:
                vesselForm.data.bhp.trim() === ''
                    ? null
                    : Number.parseInt(vesselForm.data.bhp, 10),
            official_no:
                vesselForm.data.official_no.trim() === ''
                    ? null
                    : vesselForm.data.official_no.trim(),
            call_sign:
                vesselForm.data.call_sign.trim() === ''
                    ? null
                    : vesselForm.data.call_sign.trim(),
            imo_no:
                vesselForm.data.imo_no.trim() === ''
                    ? null
                    : vesselForm.data.imo_no.trim(),
            is_active: vesselForm.data.is_active,
            redirect_to: 'show',
            ...(hasCertificate
                ? { certificate: vesselForm.data.certificate }
                : {}),
        }));

        vesselForm.put(updateVessel.url(vessel.id), {
            preserveScroll: true,
            forceFormData: hasCertificate,
            onSuccess: () => setEditOpen(false),
        });
    };

    const manningForm = useForm<VesselManningFormData>(
        toVesselManningFormData(vessel),
    );

    const openManningEdit = (): void => {
        manningForm.clearErrors();
        manningForm.setData(toVesselManningFormData(vessel));
        setManningEditOpen(true);
    };

    const submitManning = (): void => {
        manningForm.transform((data) => ({
            ...toVesselManningPayload(data),
            redirect_to: 'show' as const,
        }));
        manningForm.put(updateVesselManning.url({ vessel: vessel.id }), {
            preserveScroll: true,
            onSuccess: () => setManningEditOpen(false),
        });
    };

    const hasManningWriteAccess =
        manning_can && vesselManningHasWriteActions(manning_can);

    return (
        <Main>
            <DetailsHeader
                kicker="Crew Operations"
                title={vessel.name}
                description={`${formatDisplayValue(vessel.vessel_type?.name)} · ${vessel.is_active ? 'Active vessel' : 'Inactive vessel'}`}
                backHref={backHref}
                backLabel="Back to vessels"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {ranks && hasManningWriteAccess ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-xl"
                                onClick={openManningEdit}
                            >
                                <Pencil className="mr-2 h-4 w-4" />
                                Edit manning
                            </Button>
                        ) : null}
                        {can.update ? (
                            <Button
                                type="button"
                                className={actions.dialogPrimary}
                                onClick={openEdit}
                            >
                                <Pencil className="mr-2 h-4 w-4" />
                                Edit vessel
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="overflow-hidden glass-card lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="border-b border-border pb-5 dark:border-white/5">
                        <div className="flex items-start gap-4">
                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                <Ship className="h-7 w-7" />
                            </div>
                            <div className="min-w-0 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        className={
                                            vessel.is_active
                                                ? 'border-emerald-500/20 bg-emerald-500/15 text-[10px] font-bold tracking-wider text-emerald-700 uppercase dark:text-emerald-400'
                                                : 'border border-border/80 bg-muted/60 text-[10px] font-bold tracking-wider text-muted-foreground uppercase'
                                        }
                                    >
                                        {vessel.is_active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </Badge>
                                    {vessel.vessel_type?.name ? (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] font-bold tracking-wider uppercase"
                                        >
                                            {vessel.vessel_type.name}
                                        </Badge>
                                    ) : null}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {formatDisplayValue(vessel.imo_no) !== '—'
                                        ? `IMO ${vessel.imo_no}`
                                        : 'Vessel identification and operational details.'}
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field
                                label="Vessel type"
                                value={formatDisplayValue(
                                    vessel.vessel_type?.name,
                                )}
                            />
                            <Field
                                label="GRT"
                                value={formatDisplayValue(vessel.grt)}
                            />
                            <Field
                                label="BHP"
                                value={
                                    vessel.bhp !== null &&
                                    vessel.bhp !== undefined
                                        ? `${vessel.bhp} HP`
                                        : '—'
                                }
                            />
                            <Field
                                label="Official No"
                                value={formatDisplayValue(vessel.official_no)}
                            />
                            <Field
                                label="Call Sign"
                                value={formatDisplayValue(vessel.call_sign)}
                            />
                            <Field
                                label="IMO No"
                                value={formatDisplayValue(vessel.imo_no)}
                            />
                            <Field
                                label="Certificate"
                                value={
                                    vessel.certificate_url ? (
                                        <a
                                            href={vessel.certificate_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-2 text-primary underline-offset-2 hover:underline"
                                        >
                                            <FileText className="h-4 w-4" />
                                            {vessel.certificate_original_filename ??
                                                'View certificate'}
                                        </a>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                            <div className="flex items-center justify-between gap-3 px-6 py-4">
                                <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                    Metadata
                                </div>
                                <div className="space-y-1 text-right text-sm font-medium">
                                    <div>
                                        Created:{' '}
                                        {formatDisplayDate(vessel.created_at)}
                                    </div>
                                    <div>
                                        Updated:{' '}
                                        {formatDisplayDate(vessel.updated_at)}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="border-b border-border pb-4 dark:border-white/5">
                        <CardTitle className="text-base font-bold">
                            Operational summary
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 pt-6">
                        <StatChip
                            label="Required crew"
                            value={
                                summary.total_required > 0
                                    ? String(summary.total_required)
                                    : '—'
                            }
                            icon={Users}
                            highlight={summary.total_required > 0}
                        />
                        <StatChip
                            label="Manning ranks"
                            value={String(summary.manning_ranks)}
                            icon={ShieldCheck}
                        />
                        <StatChip
                            label="Active crew"
                            value={String(summary.active_crew)}
                            icon={Anchor}
                        />
                        <StatChip
                            label="Sea services"
                            value={String(summary.sea_services)}
                            icon={Ship}
                        />
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6 glass-card dark:border-white/5 dark:bg-white/5">
                <CardHeader className="border-b border-border pb-4 dark:border-white/5">
                    <div className="flex items-center justify-between">
                        <CardTitle className="text-base font-bold">
                            Manning requirements
                        </CardTitle>
                        {ranks && hasManningWriteAccess ? (
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={openManningEdit}
                            >
                                <Pencil className="mr-2 h-4 w-4" />
                                Edit manning
                            </Button>
                        ) : null}
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    {vessel.manning.length === 0 ? (
                        <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                            No ranks configured yet.
                            {ranks && hasManningWriteAccess ? (
                                <>
                                    {' '}
                                    <button
                                        type="button"
                                        className="font-medium text-primary underline-offset-4 hover:underline"
                                        onClick={openManningEdit}
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
                                    <TableRow
                                        key={line.id}
                                        className={dataTableBodyRowClass(false)}
                                    >
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <span className="font-semibold text-foreground/80">
                                                {line.rank_name}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Badge
                                                variant="outline"
                                                className="border-primary/20 bg-primary/5 px-3 py-1 font-semibold text-foreground"
                                            >
                                                <Users className="mr-1.5 inline h-3 w-3 text-primary" />
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
                    description="Recent changes to this vessel in the current company."
                />
            ) : null}

            <VesselFormSheet
                open={editOpen}
                onOpenChange={setEditOpen}
                vessel={vessel}
                vesselTypes={vessel_types}
                form={vesselForm}
                onSubmit={submitVesselEdit}
            />

            {ranks && manning_can ? (
                <VesselManningFormSheet
                    open={manningEditOpen}
                    onOpenChange={setManningEditOpen}
                    vessel={vessel}
                    ranks={ranks}
                    form={manningForm}
                    onSubmit={submitManning}
                />
            ) : null}
        </Main>
    );
}
