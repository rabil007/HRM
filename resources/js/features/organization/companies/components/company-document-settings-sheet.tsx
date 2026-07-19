import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    destroyAsset,
    update,
} from '@/actions/App/Http/Controllers/Organization/CompanyDocumentSettingController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { BrandingUploadField } from '@/components/settings/branding-upload-field';
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
import { Textarea } from '@/components/ui/textarea';
import type {
    CompanyDocumentSettings,
    CompanyDocumentSettingsFormData,
} from '../types';

const DOCUMENT_TYPE = 'salary_certificate';

type RemoveAsset = 'signature' | 'stamp' | null;

function formDefaults(
    settings: CompanyDocumentSettings,
): CompanyDocumentSettingsFormData {
    return {
        document_type: DOCUMENT_TYPE,
        signatory_name: settings.signatory_name ?? '',
        signatory_title: settings.signatory_title ?? '',
        footer_text: settings.footer_text ?? '',
        effective_from: settings.effective_from ?? '',
        effective_to: settings.effective_to ?? '',
        signature: null,
        stamp: null,
    };
}

export function CompanyDocumentSettingsSheet({
    open,
    onOpenChange,
    companyId,
    companyName,
    settings,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: number;
    companyName: string;
    settings: CompanyDocumentSettings;
}) {
    const [removeAsset, setRemoveAsset] = useState<RemoveAsset>(null);
    const form = useForm<CompanyDocumentSettingsFormData>(
        formDefaults(settings),
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData(formDefaults(settings));
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, settings]);

    const submit = () => {
        form.put(update.url(companyId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const confirmRemove = () => {
        if (!removeAsset) {
            return;
        }

        router.delete(
            destroyAsset.url(
                { company: companyId, asset: removeAsset },
                { query: { document_type: DOCUMENT_TYPE } },
            ),
            {
                preserveScroll: true,
                onSuccess: () => setRemoveAsset(null),
            },
        );
    };

    return (
        <>
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
                >
                    <SheetHeader className="border-b border-border/60 p-8 pb-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            Salary certificate settings
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                            Signatory, signature, and stamp for printed salary
                            certificates.
                        </SheetDescription>
                        <p className="mt-3 text-xs font-medium text-muted-foreground">
                            Current company:{' '}
                            <span className="text-foreground">
                                {companyName}
                            </span>
                        </p>
                    </SheetHeader>

                    <div className="flex-1 space-y-8 overflow-y-auto p-8">
                        {settings.using_legacy_signature ||
                        settings.using_legacy_stamp ? (
                            <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                                {settings.using_legacy_signature &&
                                settings.using_legacy_stamp
                                    ? 'Signature and stamp are currently falling back to legacy application branding. Upload company assets here to override them.'
                                    : settings.using_legacy_signature
                                      ? 'Signature is currently falling back to legacy application branding. Upload a company signature to override it.'
                                      : 'Stamp is currently falling back to legacy application branding. Upload a company stamp to override it.'}
                            </div>
                        ) : null}

                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="signatory_name"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Signatory name
                                </Label>
                                <Input
                                    id="signatory_name"
                                    placeholder="Authorized signatory"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.signatory_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'signatory_name',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.signatory_name ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.signatory_name}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="signatory_title"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Signatory title
                                </Label>
                                <Input
                                    id="signatory_title"
                                    placeholder="HR Manager"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.signatory_title}
                                    onChange={(e) =>
                                        form.setData(
                                            'signatory_title',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.signatory_title ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.signatory_title}
                                    </div>
                                ) : null}
                            </div>

                            <BrandingUploadField
                                key={`signature-${settings.has_signature}-${settings.signature_url ?? 'none'}`}
                                label="Authorized signature"
                                assetKey="signature"
                                currentUrl={
                                    settings.has_signature
                                        ? settings.signature_url
                                        : null
                                }
                                accept="image/png,image/jpeg,image/jpg"
                                hint="PNG or JPG — max 2 MB"
                                onFileChange={(file) =>
                                    form.setData('signature', file)
                                }
                                onRemove={() => setRemoveAsset('signature')}
                                error={form.errors.signature}
                            />

                            <BrandingUploadField
                                key={`stamp-${settings.has_stamp}-${settings.stamp_url ?? 'none'}`}
                                label="Company stamp"
                                assetKey="stamp"
                                currentUrl={
                                    settings.has_stamp
                                        ? settings.stamp_url
                                        : null
                                }
                                accept="image/png,image/jpeg,image/jpg"
                                hint="PNG or JPG — max 2 MB"
                                onFileChange={(file) =>
                                    form.setData('stamp', file)
                                }
                                onRemove={() => setRemoveAsset('stamp')}
                                error={form.errors.stamp}
                            />

                            <div className="space-y-2">
                                <Label
                                    htmlFor="footer_text"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Footer text
                                </Label>
                                <Textarea
                                    id="footer_text"
                                    placeholder="Optional footer on printed certificates"
                                    className="min-h-24 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.footer_text}
                                    onChange={(e) =>
                                        form.setData(
                                            'footer_text',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.footer_text ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.footer_text}
                                    </div>
                                ) : null}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label
                                        htmlFor="effective_from"
                                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                    >
                                        Effective from
                                    </Label>
                                    <Input
                                        id="effective_from"
                                        type="date"
                                        className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                        value={form.data.effective_from}
                                        onChange={(e) =>
                                            form.setData(
                                                'effective_from',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.effective_from ? (
                                        <div className="text-xs font-medium text-destructive">
                                            {form.errors.effective_from}
                                        </div>
                                    ) : null}
                                </div>
                                <div className="space-y-2">
                                    <Label
                                        htmlFor="effective_to"
                                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                    >
                                        Effective to
                                    </Label>
                                    <Input
                                        id="effective_to"
                                        type="date"
                                        className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                        value={form.data.effective_to}
                                        onChange={(e) =>
                                            form.setData(
                                                'effective_to',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.effective_to ? (
                                        <div className="text-xs font-medium text-destructive">
                                            {form.errors.effective_to}
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                            className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        >
                            Cancel
                        </Button>
                        <Button
                            className="h-11 flex-1 rounded-xl px-8 font-semibold"
                            disabled={form.processing}
                            onClick={submit}
                        >
                            Save settings
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>

            <ConfirmDeleteDialog
                open={removeAsset !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRemoveAsset(null);
                    }
                }}
                title={
                    removeAsset === 'stamp'
                        ? 'Remove company stamp?'
                        : 'Remove authorized signature?'
                }
                description={
                    removeAsset === 'stamp'
                        ? 'The company stamp will be removed from salary certificates. This cannot be undone.'
                        : 'The authorized signature will be removed from salary certificates. This cannot be undone.'
                }
                confirmText="Remove"
                onConfirm={confirmRemove}
                contentClassName="glass-card sm:max-w-sm"
            />
        </>
    );
}
