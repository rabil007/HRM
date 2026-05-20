import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
import { EmailAttachmentList } from '@/features/organization/documents/email-send/attachment-list';
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
    employee: { id: number; name: string };
    organizationName: string;
    documents: EmailDocumentItem[];
    onSendComplete: () => void;
};

export function EmailDocumentsModal({
    open,
    onOpenChange,
    employee,
    organizationName,
    documents,
    onSendComplete,
}: EmailDocumentsModalProps) {
    const [recipient, setRecipient] = useState('');
    const [cc, setCc] = useState('');
    const [subject, setSubject] = useState('');
    const [message, setMessage] = useState('');
    const [isSending, setIsSending] = useState(false);

    const attachmentSizeExceeded = useMemo(
        () => isAttachmentSizeExceeded(documents),
        [documents],
    );

    useEffect(() => {
        if (open) {
            setRecipient('');
            setCc('');
            setSubject(buildDefaultEmailSubject(employee.name, organizationName));
            setMessage('');
        }
    }, [employee.name, open, organizationName]);

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
            onOpenChange(false);
            onSendComplete();
            toast.success(successMessage);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Failed to send email.');
        } finally {
            setIsSending(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
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
                        <Label htmlFor="email-recipient">Recipient email</Label>
                        <Input
                            id="email-recipient"
                            type="email"
                            value={recipient}
                            onChange={(event) => setRecipient(event.target.value)}
                            placeholder="recipient@example.com"
                            disabled={isSending}
                            className="rounded-lg border-white/10 bg-zinc-950/60"
                        />
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
                            rows={4}
                            placeholder="Add a message for the recipient…"
                            className="border-input placeholder:text-muted-foreground flex min-h-[96px] w-full rounded-lg border border-white/10 bg-zinc-950/60 px-3 py-2 text-sm text-zinc-100 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
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
