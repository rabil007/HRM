import { useForm } from '@inertiajs/react';
import { Copy, Link2 } from 'lucide-react';
import { useState } from 'react';
import CreateDocumentRecipientRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CreateDocumentRecipientRequestController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    employeeName: string;
    documentTitle: string;
    action: 'sign' | 'acknowledge';
};

type FlashPayload = {
    id: number;
    action: string;
    secure_url: string;
};

export function RequestRecipientActionDialog({
    open,
    onOpenChange,
    employeeId,
    documentId,
    employeeName,
    documentTitle,
    action,
}: Props) {
    const [secureUrl, setSecureUrl] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const form = useForm({ action });

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            setSecureUrl(null);
            setCopied(false);
            form.reset();
        }

        onOpenChange(nextOpen);
    };

    const copyLink = async () => {
        if (!secureUrl) {
            return;
        }

        await navigator.clipboard.writeText(secureUrl);
        setCopied(true);
    };

    const submit = () => {
        form.post(
            CreateDocumentRecipientRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: (responsePage) => {
                    const payload = (
                        responsePage.props as {
                            flash?: {
                                recipient_request_created?: FlashPayload;
                            };
                        }
                    ).flash?.recipient_request_created;

                    if (payload?.action === action && payload.secure_url) {
                        setSecureUrl(payload.secure_url);
                    }
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Request{' '}
                        {action === 'sign' ? 'signature' : 'acknowledgement'}
                    </DialogTitle>
                    <DialogDescription>
                        Create a secure link for the document subject employee.
                    </DialogDescription>
                </DialogHeader>

                {secureUrl ? (
                    <div className="space-y-4">
                        <div className="rounded-xl border bg-muted/20 p-4 text-sm">
                            <p className="font-medium">{documentTitle}</p>
                            <p className="mt-1 text-muted-foreground">
                                Recipient: {employeeName}
                            </p>
                        </div>
                        <div>
                            <Label>Secure link (shown once)</Label>
                            <div className="mt-1.5 flex gap-2">
                                <Input readOnly value={secureUrl} />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={copyLink}
                                >
                                    <Copy className="size-4" />
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Copy this link now. Regenerating a link
                                invalidates the previous URL immediately.
                            </p>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                onClick={() => handleOpenChange(false)}
                            >
                                Done
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <>
                        <div className="space-y-3 rounded-xl border bg-muted/20 p-4 text-sm">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Action
                                </span>
                                <span className="font-medium capitalize">
                                    {action}
                                </span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Recipient
                                </span>
                                <span className="font-medium">
                                    {employeeName}
                                </span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Document
                                </span>
                                <span className="max-w-[60%] text-right font-medium">
                                    {documentTitle}
                                </span>
                            </div>
                        </div>
                        <DialogFooter className="mt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => handleOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={submit}
                            >
                                <Link2 className="mr-1.5 size-4" />
                                {form.processing
                                    ? 'Creating…'
                                    : 'Create secure link'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
