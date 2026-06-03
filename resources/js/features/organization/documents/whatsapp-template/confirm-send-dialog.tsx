import { Loader2, MessageCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { sendWhatsAppDocumentTemplate } from '@/features/organization/documents/whatsapp-template/send-whatsapp-document-template';
import {
    groupWhatsAppTemplatesByCategory,
    resolveDefaultWhatsAppTemplate,
} from '@/features/organization/documents/whatsapp-template/types';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import {
    renderWhatsAppTemplatePreviewBody,
    WhatsAppDocumentTemplatePreview,
} from '@/features/settings/whatsapp-document-template-preview';
import {
    formatPhoneForDisplay,
    parsePhoneWithDialCode,
} from '@/lib/phone-with-dial-code';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { toast } from '@/lib/toast';
import { whatsappTemplate } from '@/routes/organization/documents/employee/files';

type ConfirmSendWhatsAppDocumentDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    employeeName: string;
    employeePhone?: string | null;
    documentId: number;
    documentName: string;
    documentTypeLabel?: string | null;
    templates?: WhatsAppTemplateOption[];
    countries: PhoneCountryOption[];
    onSendComplete?: () => void;
};

export function ConfirmSendWhatsAppDocumentDialog({
    open,
    onOpenChange,
    employeeId,
    employeeName,
    employeePhone,
    documentId,
    documentName,
    documentTypeLabel,
    templates = [],
    countries,
    onSendComplete,
}: ConfirmSendWhatsAppDocumentDialogProps) {
    const [whatsappNumber, setWhatsappNumber] = useState('');
    const [templateSlug, setTemplateSlug] = useState('');
    const [isSending, setIsSending] = useState(false);

    const templateGroups = useMemo(
        () => groupWhatsAppTemplatesByCategory(templates),
        [templates],
    );

    const defaultTemplate = useMemo(() => resolveDefaultWhatsAppTemplate(templates), [templates]);

    const selectedTemplate = useMemo(
        () => templates.find((template) => template.slug === templateSlug) ?? defaultTemplate,
        [defaultTemplate, templateSlug, templates],
    );

    const previewBody = useMemo(() => {
        if (!selectedTemplate) {
            return '';
        }

        return renderWhatsAppTemplatePreviewBody(
            selectedTemplate.body_preview,
            employeeName,
        );
    }, [employeeName, selectedTemplate]);

    const profilePhoneDisplay = useMemo(() => {
        const trimmed = employeePhone?.trim();

        if (!trimmed) {
            return null;
        }

        return formatPhoneForDisplay(trimmed, { countries, fieldKey: 'phone' });
    }, [countries, employeePhone]);

    const profileNationalPlaceholder = useMemo(() => {
        const trimmed = employeePhone?.trim();

        if (!trimmed) {
            return '501234567';
        }

        const { nationalNumber } = parsePhoneWithDialCode(trimmed, countries);

        return nationalNumber || trimmed.replace(/\D/g, '');
    }, [countries, employeePhone]);

    useEffect(() => {
        if (open) {
            setWhatsappNumber('');
            setTemplateSlug(defaultTemplate?.slug ?? '');
        }
    }, [defaultTemplate?.slug, open]);

    const handleConfirm = async () => {
        const number = whatsappNumber.trim();
        const slug = templateSlug.trim() || defaultTemplate?.slug;

        if (!number) {
            toast.error('Enter a WhatsApp number with country code.');

            return;
        }

        if (!slug) {
            toast.error('Choose a WhatsApp template.');

            return;
        }

        setIsSending(true);

        try {
            const result = await sendWhatsAppDocumentTemplate(
                whatsappTemplate.url({ employee: employeeId, document: documentId }),
                { whatsapp_number: number, template_slug: slug },
            );

            onOpenChange(false);
            onSendComplete?.();
            toast.success(`${result.message} Sent to ${number}.`);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Failed to send via WhatsApp.');
        } finally {
            setIsSending(false);
        }
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <AlertDialogHeader>
                    <div className="mb-1 flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-green-500/10 text-green-500">
                            <MessageCircle className="size-4" />
                        </span>
                        <AlertDialogTitle>Send via WhatsApp</AlertDialogTitle>
                    </div>
                    <AlertDialogDescription asChild>
                        <div className="space-y-4 text-left">
                            <p>
                                Choose a Meta template and send this file to the employee&apos;s
                                WhatsApp number.
                            </p>

                            <div className="space-y-2 rounded-lg border border-white/10 bg-zinc-950/40 px-3 py-2.5">
                                <p className="truncate text-sm font-medium text-foreground">
                                    {documentName}
                                </p>
                                {documentTypeLabel ? (
                                    <Badge
                                        variant="outline"
                                        className="border-white/10 font-normal text-muted-foreground"
                                    >
                                        Document type: {documentTypeLabel}
                                    </Badge>
                                ) : null}
                            </div>

                            {templates.length > 0 ? (
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="whatsapp-template-select">
                                            WhatsApp template{' '}
                                            <span className="text-red-400">*</span>
                                        </Label>
                                        <Select
                                            value={templateSlug}
                                            onValueChange={setTemplateSlug}
                                            disabled={isSending}
                                        >
                                            <SelectTrigger
                                                id="whatsapp-template-select"
                                                className="rounded-lg border-white/10 bg-zinc-950/60"
                                            >
                                                <SelectValue placeholder="Select template" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {templateGroups.map((group) => (
                                                    <SelectGroup key={group.category}>
                                                        <SelectLabel>
                                                            {group.category_label}
                                                        </SelectLabel>
                                                        {group.templates.map((template) => (
                                                            <SelectItem
                                                                key={template.slug}
                                                                value={template.slug}
                                                            >
                                                                {template.label}
                                                                {template.is_default
                                                                    ? ' (default)'
                                                                    : ''}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectGroup>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {selectedTemplate ? (
                                            <p className="text-xs text-muted-foreground">
                                                Meta template:{' '}
                                                <span className="font-mono text-foreground/90">
                                                    {selectedTemplate.meta_name}
                                                </span>
                                                {' · '}
                                                {selectedTemplate.meta_language}
                                            </p>
                                        ) : null}
                                    </div>

                                    {selectedTemplate ? (
                                        <WhatsAppDocumentTemplatePreview
                                            templateName={selectedTemplate.meta_name}
                                            templateLanguage={selectedTemplate.meta_language}
                                            bodyText={previewBody}
                                            sampleFileName={documentName}
                                            className="rounded-lg border border-white/10 bg-zinc-950/30 p-3"
                                        />
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-amber-200/90">
                                    No document WhatsApp templates are enabled. Add templates
                                    under Settings → Integrations → WhatsApp.
                                </p>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="whatsapp-template-number">
                                    WhatsApp number <span className="text-red-400">*</span>
                                </Label>
                                <PhoneInputWithCountry
                                    id="whatsapp-template-number"
                                    countries={countries}
                                    value={whatsappNumber}
                                    onChange={setWhatsappNumber}
                                    fieldKey="phone"
                                    defaultDialCode="+971"
                                    disabled={isSending}
                                    nationalPlaceholder={profileNationalPlaceholder}
                                    inputClassName="rounded-lg border-white/10 bg-zinc-950/60"
                                    selectClassName="rounded-lg border-white/10 bg-zinc-950/60"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {profilePhoneDisplay
                                        ? `Profile phone: ${profilePhoneDisplay}. Enter a number below if sending elsewhere.`
                                        : 'Select country code and enter the mobile number.'}
                                </p>
                            </div>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        className="rounded-xl border-white/10 bg-white/5"
                        disabled={isSending}
                    >
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={
                            isSending ||
                            whatsappNumber.trim() === '' ||
                            templates.length === 0 ||
                            !templateSlug
                        }
                        onClick={(event) => {
                            event.preventDefault();
                            void handleConfirm();
                        }}
                    >
                        {isSending ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Sending…
                            </>
                        ) : (
                            'Send via WhatsApp'
                        )}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
