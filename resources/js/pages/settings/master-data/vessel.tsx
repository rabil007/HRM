import { Head, router, useForm } from '@inertiajs/react';
import { Anchor, FileText, Ship, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { show as vesselManningShow } from '@/actions/App/Http/Controllers/Organization/VesselManningController';
import {
    index as vesselsIndex,
    update as updateVessel,
} from '@/actions/App/Http/Controllers/Settings/MasterData/VesselController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { DetailsHeader } from '@/components/details-header';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';

type VesselTypeOption = {
    id: number;
    name: string;
};

type VesselDetails = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type: { id: number; name: string } | null;
    grt: string | number | null;
    bhp: number | null;
    official_no: string | null;
    call_sign: string | null;
    imo_no: string | null;
    certificate_original_filename: string | null;
    certificate_url: string | null;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

type VesselSummary = {
    manning_ranks: number;
    sea_services: number;
    active_crew: number;
};

type VesselPageCan = {
    update: boolean;
    delete: boolean;
    view_manning: boolean;
};

function Field({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                {label}
            </div>
            <div className="text-right text-sm font-medium">{value}</div>
        </div>
    );
}

export default function VesselDetailsPage({
    vessel,
    vessel_types,
    summary,
    can,
    recent_activity,
    can_view_audit,
}: {
    vessel: VesselDetails;
    vessel_types: VesselTypeOption[];
    summary: VesselSummary;
    can: VesselPageCan;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const certificateInputRef = useRef<HTMLInputElement>(null);

    const form = useForm({
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
        certificate: null as File | null,
        is_active: vessel.is_active,
        redirect_to: 'show',
    });

    const openEdit = () => {
        form.clearErrors();
        form.setData({
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
            redirect_to: 'show',
        });

        if (certificateInputRef.current) {
            certificateInputRef.current.value = '';
        }

        setEditOpen(true);
    };

    const submit = () => {
        const hasCertificate = form.data.certificate instanceof File;

        const payload = {
            name: form.data.name,
            vessel_type_id: form.data.vessel_type_id,
            grt: form.data.grt.trim() === '' ? null : Number(form.data.grt),
            bhp:
                form.data.bhp.trim() === ''
                    ? null
                    : Number.parseInt(form.data.bhp, 10),
            official_no:
                form.data.official_no.trim() === ''
                    ? null
                    : form.data.official_no.trim(),
            call_sign:
                form.data.call_sign.trim() === ''
                    ? null
                    : form.data.call_sign.trim(),
            imo_no:
                form.data.imo_no.trim() === '' ? null : form.data.imo_no.trim(),
            is_active: form.data.is_active,
            redirect_to: 'show',
            ...(hasCertificate ? { certificate: form.data.certificate } : {}),
        };

        form.transform(() => payload);
        form.put(updateVessel.url(vessel.id), {
            preserveScroll: true,
            forceFormData: hasCertificate,
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <>
            <Head title={`Vessel • ${vessel.name}`} />

            <div className="space-y-6">
                <DetailsHeader
                    kicker="Master data"
                    title={vessel.name}
                    description="Vessel identification, tonnage, and certificate details."
                    backHref={vesselsIndex.url()}
                    backLabel="Back to vessels"
                    actions={
                        can.update ? (
                            <Button
                                variant="outline"
                                className="h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                                onClick={openEdit}
                            >
                                Edit
                            </Button>
                        ) : null
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="overflow-hidden glass-card lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-4">
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                    <Ship className="h-7 w-7" />
                                </div>
                                <div className="min-w-0">
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
                                    <div className="mt-2 text-sm text-muted-foreground/80">
                                        {formatDisplayValue(vessel.imo_no) !==
                                        '—'
                                            ? `IMO ${vessel.imo_no}`
                                            : 'No IMO number'}
                                    </div>
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
                                    value={formatDisplayValue(vessel.bhp)}
                                />
                                <Field
                                    label="Official No"
                                    value={formatDisplayValue(
                                        vessel.official_no,
                                    )}
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
                                            {formatDisplayDate(
                                                vessel.created_at,
                                            )}
                                        </div>
                                        <div>
                                            Updated:{' '}
                                            {formatDisplayDate(
                                                vessel.updated_at,
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-lg font-bold tracking-tight">
                                Quick actions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <Anchor className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Manning ranks
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {summary.manning_ranks}
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <Users className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Active crew
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {summary.active_crew}
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <Ship className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Sea services
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {summary.sea_services}
                                    </div>
                                </div>
                            </div>

                            {can.view_manning ? (
                                <Button
                                    className="mt-2 w-full rounded-xl"
                                    onClick={() =>
                                        router.visit(
                                            vesselManningShow.url(vessel.id),
                                        )
                                    }
                                >
                                    Open vessel manning
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Recent changes to this vessel in the current company."
                    />
                ) : null}
            </div>

            <Sheet open={editOpen} onOpenChange={setEditOpen}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
                >
                    <SheetHeader className="border-b border-border/60 p-8 pb-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            Edit vessel
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                            Update identification, tonnage, and certificate.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-5 overflow-y-auto p-8">
                        <div className="space-y-2">
                            <Label htmlFor="show-name">Name</Label>
                            <Input
                                id="show-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="show-vessel_type_id">
                                Vessel type
                            </Label>
                            <AppSelect
                                value={
                                    form.data.vessel_type_id === ''
                                        ? ''
                                        : String(form.data.vessel_type_id)
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'vessel_type_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="dark"
                                placeholder="Select type"
                            >
                                <AppSelectItem value="">—</AppSelectItem>
                                {vessel_types.map((type) => (
                                    <AppSelectItem
                                        key={type.id}
                                        value={String(type.id)}
                                    >
                                        {type.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.vessel_type_id ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.vessel_type_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="show-grt">GRT</Label>
                                <Input
                                    id="show-grt"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.grt}
                                    onChange={(e) =>
                                        form.setData('grt', e.target.value)
                                    }
                                />
                                {form.errors.grt ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.grt}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="show-bhp">BHP</Label>
                                <Input
                                    id="show-bhp"
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.bhp}
                                    onChange={(e) =>
                                        form.setData('bhp', e.target.value)
                                    }
                                />
                                {form.errors.bhp ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.bhp}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="show-official_no">
                                Official No
                            </Label>
                            <Input
                                id="show-official_no"
                                value={form.data.official_no}
                                onChange={(e) =>
                                    form.setData('official_no', e.target.value)
                                }
                            />
                            {form.errors.official_no ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.official_no}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="show-call_sign">
                                    Call Sign
                                </Label>
                                <Input
                                    id="show-call_sign"
                                    value={form.data.call_sign}
                                    onChange={(e) =>
                                        form.setData(
                                            'call_sign',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.call_sign ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.call_sign}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="show-imo_no">IMO No</Label>
                                <Input
                                    id="show-imo_no"
                                    value={form.data.imo_no}
                                    onChange={(e) =>
                                        form.setData('imo_no', e.target.value)
                                    }
                                />
                                {form.errors.imo_no ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.imo_no}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="show-certificate">
                                Certificate of vessel
                            </Label>
                            <Input
                                ref={certificateInputRef}
                                id="show-certificate"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                onChange={(e) =>
                                    form.setData(
                                        'certificate',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {form.data.certificate ? (
                                <div className="text-xs text-muted-foreground">
                                    Selected: {form.data.certificate.name}
                                </div>
                            ) : vessel.certificate_url ? (
                                <div className="text-xs text-muted-foreground">
                                    Current:{' '}
                                    <a
                                        href={vessel.certificate_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-primary underline-offset-2 hover:underline"
                                    >
                                        {vessel.certificate_original_filename ??
                                            'View certificate'}
                                    </a>
                                </div>
                            ) : (
                                <div className="text-xs text-muted-foreground">
                                    PDF, JPG, or PNG up to 5 MB.
                                </div>
                            )}
                            {form.errors.certificate ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.certificate}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold">
                                    Active
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Disable to hide from selections.
                                </div>
                            </div>
                            <Switch
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v)
                                }
                            />
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button
                            type="button"
                            variant="ghost"
                            className="flex-1"
                            onClick={() => setEditOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="flex-1"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
