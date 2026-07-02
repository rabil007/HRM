import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmailAttachmentList } from '@/features/organization/documents/email-send/attachment-list';
import type { EmailDocumentItem } from '@/features/organization/documents/email-send/types';
import { sendDocumentsWhatsApp } from '@/features/organization/documents/whatsapp-send/send-documents-whatsapp';
import type { WhatsAppDocumentItem } from '@/features/organization/documents/whatsapp-send/types';
import { toast } from '@/lib/toast';
import { whatsapp as whatsappDocuments } from '@/routes/organization/documents/employee/files';

type WhatsAppDocumentsModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: { id: number; name: string; phone?: string | null };
    documents: WhatsAppDocumentItem[];
    onSendComplete: () => void;
};

export function WhatsAppDocumentsModal({
    open,
    onOpenChange,
    employee,
    documents,
    onSendComplete,
}: WhatsAppDocumentsModalProps) {
    const [whatsappNumber, setWhatsappNumber] = useState('');
    const [sendTemplateFirst, setSendTemplateFirst] = useState(true);
    const [isSending, setIsSending] = useState(false);

    useEffect(() => {
        if (open) {
            setWhatsappNumber(employee.phone?.trim() ?? '');
            setSendTemplateFirst(true);
        }
    }, [employee.phone, open]);

    const handleSend = async () => {
        const number = whatsappNumber.trim();

        if (!number) {
            toast.error('Enter a WhatsApp number with country code.');

            return;
        }

        setIsSending(true);

        try {
            const result = await sendDocumentsWhatsApp(
                whatsappDocuments.url({ employee: employee.id }),
                {
                    document_ids: documents.map((document) => document.id),
                    whatsapp_number: number,
                    send_template_first: sendTemplateFirst,
                },
            );

            onOpenChange(false);
            onSendComplete();
            toast.success(`${result.message} Sent to ${number}.`);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to send via WhatsApp.',
            );
        } finally {
            setIsSending(false);
        }
    };

    const attachmentItems: EmailDocumentItem[] = documents;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
                <DialogHeader className="border-b border-border px-5 py-4 dark:border-white/10">
                    <DialogTitle>Direct WhatsApp</DialogTitle>
                </DialogHeader>

                <div className="space-y-4 overflow-y-auto px-5 py-4">
                    <EmailAttachmentList documents={attachmentItems} />

                    <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-sm text-amber-100/90">
                        <p>
                            Files are sent via the WhatsApp Business API as
                            document attachments. They only appear on the phone
                            if the recipient messaged your business number
                            within 24 hours, or is on your Meta test recipient
                            list.
                        </p>
                        <p className="mt-2">
                            The{' '}
                            <span className="font-medium">
                                hello_world template
                            </span>{' '}
                            sends a greeting only — it does not attach your PDF.
                            Enable it below to notify new contacts; they must
                            reply before documents can be delivered.
                        </p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="whatsapp-number">
                            WhatsApp number{' '}
                            <span className="text-red-400">*</span>
                        </Label>
                        <Input
                            id="whatsapp-number"
                            type="tel"
                            value={whatsappNumber}
                            onChange={(event) =>
                                setWhatsappNumber(event.target.value)
                            }
                            placeholder="+971501234567"
                            autoComplete="tel"
                            required
                            disabled={isSending}
                            className="rounded-lg border-border bg-muted/50 dark:border-white/10 dark:bg-zinc-950/60"
                        />
                        <p className="text-xs text-muted-foreground">
                            {employee.phone
                                ? 'Prefilled from the employee phone. Change it if sending elsewhere.'
                                : 'Include country code (e.g. +971563769023).'}
                        </p>
                    </div>

                    <label className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-3 py-3 dark:border-white/10 dark:bg-zinc-950/40">
                        <Checkbox
                            checked={sendTemplateFirst}
                            onCheckedChange={(checked) =>
                                setSendTemplateFirst(checked === true)
                            }
                            disabled={isSending}
                            className="mt-0.5"
                        />
                        <span className="space-y-1">
                            <span className="block text-sm font-medium text-foreground">
                                Send hello_world template first
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Recommended for contacts who have not messaged
                                you recently. Sends a Meta-approved greeting
                                before the document(s).
                            </span>
                        </span>
                    </label>
                </div>

                <DialogFooter className="border-t border-border px-5 py-4 sm:justify-end dark:border-white/10">
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
                            whatsappNumber.trim() === '' ||
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
                            'Send via WhatsApp'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
