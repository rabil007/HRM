import { useForm } from '@inertiajs/react';
import {
    Anchor,
    CalendarDays,
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
import { formatDisplayDate } from '@/lib/format-date';
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

function SectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex items-center gap-3 px-6 pt-5 pb-3">
            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/50 uppercase">
                {children}
            </span>
            <div className="h-px flex-1 bg-border/50 dark:bg-white/5" />
        </div>
    );
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-3.5">
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
    accent = 'default',
}: {
    label: string;
    value: string;
    icon?: React.ComponentType<{ className?: string }>;
    highlight?: boolean;
    accent?: 'default' | 'emerald' | 'blue' | 'amber';
}) {
    const accentStyles = {
        default: {
            wrapper:
                'border-border/80 bg-muted/20 dark:border-white/10 dark:bg-white/3',
            icon: 'border-border/60 bg-muted/40 text-muted-foreground dark:border-white/8 dark:bg-white/5',
        },
        emerald: {
            wrapper:
                'border-emerald-500/20 bg-emerald-500/[0.06] dark:bg-emerald-500/[0.04]',
            icon: 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        },
        blue: {
            wrapper:
                'border-blue-500/20 bg-blue-500/[0.06] dark:bg-blue-500/[0.04]',
            icon: 'border-blue-500/20 bg-blue-500/10 text-blue-600 dark:text-blue-400',
        },
        amber: {
            wrapper:
                'border-amber-500/20 bg-amber-500/[0.06] dark:bg-amber-500/[0.04]',
            icon: 'border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-400',
        },
    };

    const resolvedStyles = highlight
        ? accent !== 'default'
            ? accentStyles[accent]
            : accentStyles.emerald
        : accentStyles.default;

    return (
        <div
            className={cn(
                'group relative overflow-hidden rounded-xl border p-4 transition-all duration-300 hover:shadow-xs',
                resolvedStyles.wrapper,
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
                            resolvedStyles.icon,
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
                description={
                    [
                        vessel.vessel_type?.name ?? null,
                        vessel.is_active ? 'Active vessel' : 'Inactive vessel',
                    ]
                        .filter(Boolean)
                        .join(' · ')
                }
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
                                    {vessel.imo_no
                                        ? `IMO ${vessel.imo_no}`
                                        : 'Vessel identification and operational details.'}
                                </p>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <SectionLabel>Identification</SectionLabel>
                            <Field
                                label="IMO No"
                                value={
                                    vessel.imo_no ? (
                                        <span className="font-mono text-xs tracking-wide">
                                            {vessel.imo_no}
                                        </span>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                            <Field
                                label="Official No"
                                value={
                                    vessel.official_no ? (
                                        <span className="font-mono text-xs tracking-wide">
                                            {vessel.official_no}
                                        </span>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                            <Field
                                label="Call Sign"
                                value={
                                    vessel.call_sign ? (
                                        <span className="font-mono text-xs tracking-wide">
                                            {vessel.call_sign}
                                        </span>
                                    ) : (
                                        '—'
                                    )
                                }
                            />

                            <SectionLabel>Technical</SectionLabel>
                            <Field
                                label="Vessel Type"
                                value={
                                    vessel.vessel_type?.name ? (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] font-bold tracking-wider uppercase"
                                        >
                                            {vessel.vessel_type.name}
                                        </Badge>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                            <Field
                                label="GRT"
                                value={
                                    vessel.grt !== null &&
                                    vessel.grt !== undefined
                                        ? String(vessel.grt)
                                        : '—'
                                }
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

                            <SectionLabel>Documentation</SectionLabel>
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

                            <SectionLabel>Record</SectionLabel>
                            <div className="grid grid-cols-2 divide-x divide-border px-6 py-3.5 dark:divide-white/5">
                                <div className="space-y-1 pr-6">
                                    <div className="flex items-center gap-1.5">
                                        <CalendarDays className="h-3.5 w-3.5 text-muted-foreground/60" />
                                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                            Created
                                        </span>
                                    </div>
                                    <div className="text-sm font-medium">
                                        {formatDisplayDate(vessel.created_at)}
                                    </div>
                                </div>
                                <div className="space-y-1 pl-6">
                                    <div className="flex items-center gap-1.5">
                                        <CalendarDays className="h-3.5 w-3.5 text-muted-foreground/60" />
                                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                            Updated
                                        </span>
                                    </div>
                                    <div className="text-sm font-medium">
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
                            accent="blue"
                        />
                        <StatChip
                            label="Manning ranks"
                            value={String(summary.manning_ranks)}
                            icon={ShieldCheck}
                            highlight={summary.manning_ranks > 0}
                            accent="emerald"
                        />
                        <StatChip
                            label="Active crew"
                            value={String(summary.active_crew)}
                            icon={Anchor}
                            highlight={summary.active_crew > 0}
                            accent="emerald"
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
                        <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                            <div className="flex size-12 items-center justify-center rounded-full border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/5">
                                <ShieldCheck className="size-5 text-muted-foreground/60" />
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm font-semibold text-foreground/80">
                                    No ranks configured
                                </p>
                                <p className="text-xs text-muted-foreground/70">
                                    Define the crew requirements for this vessel.
                                </p>
                            </div>
                            {ranks && hasManningWriteAccess ? (
                                <button
                                    type="button"
                                    className="mt-1 text-sm font-semibold text-primary underline-offset-4 hover:underline"
                                    onClick={openManningEdit}
                                >
                                    Set up manning
                                </button>
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
                            {vessel.manning.length > 0 ? (
                                <tfoot>
                                    <tr className="border-t border-border bg-muted/20 dark:border-white/5 dark:bg-white/[0.02]">
                                        <td className="px-4 py-3 text-xs font-bold tracking-wider text-muted-foreground/70 uppercase">
                                            {vessel.manning.length}{' '}
                                            {vessel.manning.length === 1
                                                ? 'rank'
                                                : 'ranks'}{' '}
                                            total
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center gap-1.5 rounded-lg border border-blue-500/20 bg-blue-500/10 px-2.5 py-1 text-xs font-bold tabular-nums text-blue-700 dark:text-blue-400">
                                                <Users className="h-3.5 w-3.5" />
                                                {vessel.manning.reduce(
                                                    (acc, l) =>
                                                        acc + l.required_count,
                                                    0,
                                                )}{' '}
                                                required
                                            </span>
                                        </td>
                                    </tr>
                                </tfoot>
                            ) : null}
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
