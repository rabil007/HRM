import { Loader2, MessageCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { sendWhatsAppDocumentTemplate } from '@/features/organization/documents/whatsapp-template/send-whatsapp-document-template';
import {
    resolveDefaultWhatsAppTemplate
    
} from '@/features/organization/documents/whatsapp-template/types';
import type {WhatsAppTemplateOption} from '@/features/organization/documents/whatsapp-template/types';
import { toast } from '@/lib/toast';
import { whatsappTemplate } from '@/routes/organization/documents/employee/files';

type ConfirmSendWhatsAppDocumentDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    employeePhone?: string | null;
    documentId: number;
    documentName: string;
    templates?: WhatsAppTemplateOption[];
    onSendComplete?: () => void;
};

export function ConfirmSendWhatsAppDocumentDialog({
    open,
    onOpenChange,
    employeeId,
    employeePhone,
    documentId,
    documentName,
    templates = [],
    onSendComplete,
}: ConfirmSendWhatsAppDocumentDialogProps) {
    const [whatsappNumber, setWhatsappNumber] = useState('');
    const [templateSlug, setTemplateSlug] = useState('');
    const [isSending, setIsSending] = useState(false);

    const defaultTemplate = useMemo(() => resolveDefaultWhatsAppTemplate(templates), [templates]);

    const selectedTemplate = useMemo(
        () => templates.find((template) => template.slug === templateSlug) ?? defaultTemplate,
        [defaultTemplate, templateSlug, templates],
    );

    useEffect(() => {
        if (open) {
            setWhatsappNumber(employeePhone?.trim() ?? '');
            setTemplateSlug(defaultTemplate?.slug ?? '');
        }
    }, [defaultTemplate?.slug, employeePhone, open]);

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
            <AlertDialogContent className="glass-card sm:max-w-md">
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
                                Send this document using the{' '}
                                <span className="font-medium text-foreground">
                                    {selectedTemplate?.meta_name ?? 'document_delivery'}
                                </span>{' '}
                                Meta template.
                            </p>
                            <p className="font-medium text-foreground">{documentName}</p>

                            {templates.length > 1 ? (
                                <div className="space-y-2">
                                    <Label htmlFor="whatsapp-template-select">Template</Label>
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
                                            {templates.map((template) => (
                                                <SelectItem
                                                    key={template.slug}
                                                    value={template.slug}
                                                >
                                                    {template.label}
                                                    {template.is_default ? ' (default)' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : null}

                            <div className="space-y-2">
                                <Label htmlFor="whatsapp-template-number">
                                    WhatsApp number <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="whatsapp-template-number"
                                    type="tel"
                                    value={whatsappNumber}
                                    onChange={(event) => setWhatsappNumber(event.target.value)}
                                    placeholder="+971501234567"
                                    autoComplete="tel"
                                    disabled={isSending}
                                    className="rounded-lg border-white/10 bg-zinc-950/60"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {employeePhone
                                        ? 'Prefilled from the employee profile. Edit if sending elsewhere.'
                                        : 'Include country code (e.g. +971563769023).'}
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
                        disabled={isSending || whatsappNumber.trim() === ''}
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
