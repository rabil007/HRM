import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { EmailAttachmentList } from '@/features/organization/documents/email-send/attachment-list';
import {
    applyEmailTemplateToFields,
    CUSTOM_EMAIL_TEMPLATE_VALUE,
    resolveDefaultEmailTemplate,
} from '@/features/organization/documents/email-send/email-template-types';
import type { EmailTemplateOption } from '@/features/organization/documents/email-send/email-template-types';
import {
    buildDefaultEmailSubject,
    isAttachmentSizeExceeded,
} from '@/features/organization/documents/email-send/email-utils';
import { sendDocumentsEmail } from '@/features/organization/documents/email-send/send-documents-email';
import type { EmailDocumentItem } from '@/features/organization/documents/email-send/types';
import { toast } from '@/lib/toast';
import { email as emailDocuments } from '@/routes/organization/documents/employee/files';

type EmailDocumentsModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: { id: number; name: string; email?: string | null };
    organizationName: string;
    documents: EmailDocumentItem[];
    emailTemplates?: EmailTemplateOption[];
    onSendComplete: () => void;
};

export function EmailDocumentsModal({
    open,
    onOpenChange,
    employee,
    organizationName,
    documents,
    emailTemplates = [],
    onSendComplete,
}: EmailDocumentsModalProps) {
    const [recipient, setRecipient] = useState('');
    const [cc, setCc] = useState('');
    const [subject, setSubject] = useState('');
    const [message, setMessage] = useState('');
    const [templateSlug, setTemplateSlug] = useState('');
    const [isSending, setIsSending] = useState(false);

    const defaultTemplate = useMemo(
        () => resolveDefaultEmailTemplate(emailTemplates),
        [emailTemplates],
    );

    const employeeEmail = employee.email?.trim() ?? '';

    const applyTemplate = useCallback(
        (slug: string) => {
            if (slug === CUSTOM_EMAIL_TEMPLATE_VALUE) {
                setRecipient(employeeEmail);
                setCc('');
                setSubject(buildDefaultEmailSubject(employee.name, organizationName));
                setMessage('');

                return;
            }

            const template = emailTemplates.find((item) => item.slug === slug);

            if (!template) {
                return;
            }

            const fields = applyEmailTemplateToFields(template, employeeEmail);
            setRecipient(fields.recipient);
            setCc(fields.cc);
            setSubject(fields.subject);
            setMessage(fields.message);
        },
        [emailTemplates, employee.name, employeeEmail, organizationName],
    );

    const attachmentSizeExceeded = useMemo(
        () => isAttachmentSizeExceeded(documents),
        [documents],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        if (defaultTemplate) {
            setTemplateSlug(defaultTemplate.slug);
            const fields = applyEmailTemplateToFields(defaultTemplate, employeeEmail);
            setRecipient(fields.recipient);
            setCc(fields.cc);
            setSubject(fields.subject);
            setMessage(fields.message);
        } else {
            setTemplateSlug(CUSTOM_EMAIL_TEMPLATE_VALUE);
            setRecipient(employeeEmail);
            setCc('');
            setSubject(buildDefaultEmailSubject(employee.name, organizationName));
            setMessage('');
        }
    }, [defaultTemplate, employeeEmail, employee.name, open, organizationName]);

    const handleTemplateChange = (slug: string) => {
        setTemplateSlug(slug);
        applyTemplate(slug);
    };

    const handleSend = async () => {
        if (attachmentSizeExceeded) {
            toast.error('Total attachment size exceeds the 20 MB limit.');

            return;
        }

        setIsSending(true);

        try {
            const successMessage = await sendDocumentsEmail(
                emailDocuments.url({ employee: employee.id }),
                {
                    document_ids: documents.map((document) => document.id),
                    recipient: recipient.trim(),
                    cc: cc.trim() || undefined,
                    subject: subject.trim(),
                    message: message.trim() || undefined,
                },
            );
            const sentTo = recipient.trim();
            onOpenChange(false);
            onSendComplete();
            toast.success(`${successMessage} Sent to ${sentTo}.`);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Failed to send email.');
        } finally {
            setIsSending(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-white/10 px-5 py-4">
                    <DialogTitle>Email documents</DialogTitle>
                </DialogHeader>

                <div className="space-y-4 overflow-y-auto px-5 py-4">
                    <EmailAttachmentList documents={documents} />

                    {attachmentSizeExceeded ? (
                        <p className="rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-300">
                            Total attachment size exceeds the 20 MB limit. Remove some files before
                            sending.
                        </p>
                    ) : null}

                    <div className="space-y-2">
                        <Label htmlFor="email-recipient">
                            Recipient email <span className="text-red-400">*</span>
                        </Label>
                        <Input
                            id="email-recipient"
                            type="email"
                            value={recipient}
                            onChange={(event) => setRecipient(event.target.value)}
                            placeholder="your.email@company.com"
                            autoComplete="email"
                            required
                            disabled={isSending}
                            className="rounded-lg border-white/10 bg-zinc-950/60"
                        />
                        <p className="text-xs text-muted-foreground">
                            {employee.email
                                ? 'Prefilled from the employee work/personal email. Change it if you want documents sent elsewhere.'
                                : 'Required. Enter the inbox that should receive these files (e.g. your Outlook address).'}
                        </p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="email-cc">CC (optional)</Label>
                        <Input
                            id="email-cc"
                            type="text"
                            value={cc}
                            onChange={(event) => setCc(event.target.value)}
                            placeholder="cc1@example.com, cc2@example.com"
                            disabled={isSending}
                            className="rounded-lg border-white/10 bg-zinc-950/60"
                        />
                    </div>

                    {emailTemplates.length > 0 ? (
                        <div className="space-y-2">
                            <Label htmlFor="email-template">Email template</Label>
                            <Select
                                value={templateSlug}
                                onValueChange={handleTemplateChange}
                                disabled={isSending}
                            >
                                <SelectTrigger
                                    id="email-template"
                                    className="rounded-lg border-white/10 bg-zinc-950/60"
                                >
                                    <SelectValue placeholder="Choose a template" />
                                </SelectTrigger>
                                <SelectContent>
                                    {emailTemplates.map((template) => (
                                        <SelectItem key={template.slug} value={template.slug}>
                                            {template.label}
                                            {template.is_default ? ' (default)' : ''}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={CUSTOM_EMAIL_TEMPLATE_VALUE}>
                                        Custom subject & message
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Loads the saved subject and message from Settings. Edit either field
                                before sending.
                            </p>
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <Label htmlFor="email-subject">Subject</Label>
                        <Input
                            id="email-subject"
                            type="text"
                            value={subject}
                            onChange={(event) => setSubject(event.target.value)}
                            disabled={isSending}
                            className="rounded-lg border-white/10 bg-zinc-950/60"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="email-message">Message (optional)</Label>
                        <textarea
                            id="email-message"
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                            disabled={isSending}
                            rows={10}
                            placeholder="Add a message for the recipient…"
                            className="border-input placeholder:text-muted-foreground flex min-h-[220px] w-full resize-y rounded-lg border border-white/10 bg-zinc-950/60 px-3 py-3 text-sm leading-relaxed text-zinc-100 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                        />
                    </div>
                </div>

                <DialogFooter className="border-t border-white/10 px-5 py-4 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        className="rounded-lg"
                        disabled={isSending}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="rounded-lg"
                        disabled={
                            isSending ||
                            attachmentSizeExceeded ||
                            recipient.trim() === '' ||
                            subject.trim() === '' ||
                            documents.length === 0
                        }
                        onClick={handleSend}
                    >
                        {isSending ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Sending…
                            </>
                        ) : (
                            'Send email'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
