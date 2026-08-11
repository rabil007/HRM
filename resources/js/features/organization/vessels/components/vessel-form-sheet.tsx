import type { InertiaFormProps } from '@inertiajs/react';
import { useRef } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
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
import type {
    VesselDetails,
    VesselFormData,
    VesselRow,
    VesselTypeOption,
} from '../types';

type VesselLike = VesselRow | VesselDetails | null;

const fieldLabelClass =
    'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase';
const fieldInputClass =
    'h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40';

export function VesselFormSheet({
    open,
    onOpenChange,
    vessel,
    vesselTypes,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    vessel: VesselLike;
    vesselTypes: VesselTypeOption[];
    form: InertiaFormProps<VesselFormData>;
    onSubmit: () => void;
}) {
    const certificateInputRef = useRef<HTMLInputElement>(null);
    const isEditing = vessel !== null;

    const currentCertificateUrl =
        vessel && 'certificate_url' in vessel ? vessel.certificate_url : null;
    const currentCertificateFilename =
        vessel && 'certificate_original_filename' in vessel
            ? vessel.certificate_original_filename
            : null;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {isEditing ? 'Edit Vessel' : 'New Vessel'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {isEditing
                            ? 'Update vessel identification and certificate details.'
                            : 'Register a vessel for this company. Identification and certificate are optional.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="vessel-name"
                                className={fieldLabelClass}
                            >
                                Vessel Name
                            </Label>
                            <Input
                                id="vessel-name"
                                className={fieldInputClass}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="ADNOC 951"
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="vessel-type-id"
                                className={fieldLabelClass}
                            >
                                Vessel Type
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
                                className="h-11 rounded-xl"
                            >
                                {vesselTypes.map((type) => (
                                    <AppSelectItem
                                        key={type.id}
                                        value={String(type.id)}
                                    >
                                        {type.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.vessel_type_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.vessel_type_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="vessel-grt"
                                    className={fieldLabelClass}
                                >
                                    GRT
                                </Label>
                                <Input
                                    id="vessel-grt"
                                    type="number"
                                    step="0.01"
                                    className={fieldInputClass}
                                    value={form.data.grt}
                                    onChange={(e) =>
                                        form.setData('grt', e.target.value)
                                    }
                                    placeholder="4500"
                                />
                                {form.errors.grt ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.grt}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="vessel-bhp"
                                    className={fieldLabelClass}
                                >
                                    BHP
                                </Label>
                                <Input
                                    id="vessel-bhp"
                                    type="number"
                                    className={fieldInputClass}
                                    value={form.data.bhp}
                                    onChange={(e) =>
                                        form.setData('bhp', e.target.value)
                                    }
                                    placeholder="12000"
                                />
                                {form.errors.bhp ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.bhp}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="vessel-official-no"
                                className={fieldLabelClass}
                            >
                                Official No
                            </Label>
                            <Input
                                id="vessel-official-no"
                                className={fieldInputClass}
                                value={form.data.official_no}
                                onChange={(e) =>
                                    form.setData('official_no', e.target.value)
                                }
                                placeholder="Official number"
                            />
                            {form.errors.official_no ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.official_no}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="vessel-call-sign"
                                    className={fieldLabelClass}
                                >
                                    Call Sign
                                </Label>
                                <Input
                                    id="vessel-call-sign"
                                    className={fieldInputClass}
                                    value={form.data.call_sign}
                                    onChange={(e) =>
                                        form.setData(
                                            'call_sign',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Call sign"
                                />
                                {form.errors.call_sign ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.call_sign}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="vessel-imo-no"
                                    className={fieldLabelClass}
                                >
                                    IMO No
                                </Label>
                                <Input
                                    id="vessel-imo-no"
                                    className={fieldInputClass}
                                    value={form.data.imo_no}
                                    onChange={(e) =>
                                        form.setData('imo_no', e.target.value)
                                    }
                                    placeholder="IMO number"
                                />
                                {form.errors.imo_no ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.imo_no}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="vessel-certificate"
                                className={fieldLabelClass}
                            >
                                Certificate
                            </Label>
                            <Input
                                ref={certificateInputRef}
                                id="vessel-certificate"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                className="h-11 rounded-xl border-border bg-card transition-all file:mr-4 file:rounded-lg file:border-0 file:bg-muted file:px-3 file:py-2 file:text-xs file:font-semibold file:text-foreground focus-visible:ring-primary/40"
                                onChange={(e) =>
                                    form.setData(
                                        'certificate',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {form.data.certificate ? (
                                <div className="text-xs text-muted-foreground/80">
                                    Selected: {form.data.certificate.name}
                                </div>
                            ) : currentCertificateUrl ? (
                                <div className="text-xs text-muted-foreground/80">
                                    Current:{' '}
                                    <a
                                        href={currentCertificateUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-primary underline-offset-2 hover:underline"
                                    >
                                        {currentCertificateFilename ??
                                            'View certificate'}
                                    </a>
                                </div>
                            ) : (
                                <div className="text-xs text-muted-foreground/80">
                                    PDF, JPG, or PNG up to 5 MB.
                                </div>
                            )}
                            {form.errors.certificate ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.certificate}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-semibold text-foreground">
                                    Active
                                </div>
                                <div className="truncate text-xs text-muted-foreground/80">
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
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="h-11 flex-1 rounded-xl px-8 font-semibold"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {form.processing
                            ? 'Saving…'
                            : isEditing
                              ? 'Save Changes'
                              : 'Create Vessel'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
