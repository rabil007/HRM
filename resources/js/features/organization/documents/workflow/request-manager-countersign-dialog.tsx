import { useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import CreateDocumentManagerCountersignRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CreateDocumentManagerCountersignRequestController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    employeeName: string;
    documentTitle: string;
    currentSourceVersion: number | null | undefined;
    resolvedManager: {
        id: number;
        name: string;
        email: string | null;
    } | null;
};

type FlashPayload = {
    id: number;
    recipient_name: string;
    respond_url: string;
};

export function RequestManagerCountersignDialog({
    open,
    onOpenChange,
    employeeId,
    documentId,
    employeeName,
    documentTitle,
    currentSourceVersion,
    resolvedManager,
}: Props) {
    const [success, setSuccess] = useState<FlashPayload | null>(null);
    const [copied, setCopied] = useState(false);

    const form = useForm({});

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            setSuccess(null);
            setCopied(false);
            form.clearErrors();
        }

        onOpenChange(nextOpen);
    };

    const copyUrl = async () => {
        if (!success?.respond_url) {
            return;
        }

        await navigator.clipboard.writeText(success.respond_url);
        setCopied(true);
    };

    const submit = () => {
        form.post(
            CreateDocumentManagerCountersignRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: (responsePage) => {
                    const payload = (
                        responsePage.props as {
                            flash?: {
                                manager_countersign_request_created?: FlashPayload;
                            };
                        }
                    ).flash?.manager_countersign_request_created;

                    if (payload) {
                        setSuccess(payload);
                    }
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Request manager countersignature</DialogTitle>
                    <DialogDescription>
                        The manager is resolved automatically from the
                        employee&apos;s department management hierarchy.
                    </DialogDescription>
                </DialogHeader>

                {success ? (
                    <div className="space-y-4">
                        <div className="rounded-xl border bg-muted/20 p-4 text-sm">
                            <p className="font-medium">
                                Manager countersignature assigned to{' '}
                                {success.recipient_name}.
                            </p>
                            <p className="mt-2 text-muted-foreground">
                                Recipient must sign in with their assigned
                                account.
                            </p>
                        </div>
                        <div>
                            <Label>Internal signing URL</Label>
                            <div className="mt-1.5 flex gap-2">
                                <input
                                    readOnly
                                    value={success.respond_url}
                                    className="flex h-9 w-full rounded-md border bg-background px-3 text-sm"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={copyUrl}
                                >
                                    <Copy className="size-4" />
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
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
                                    Role
                                </span>
                                <span className="font-medium">
                                    Department manager
                                </span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Resolved recipient
                                </span>
                                <span className="max-w-[60%] text-right font-medium">
                                    {resolvedManager?.name ?? '—'}
                                    {resolvedManager?.email
                                        ? ` (${resolvedManager.email})`
                                        : ''}
                                </span>
                            </div>
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Subject employee
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
                            {currentSourceVersion ? (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Source version
                                    </span>
                                    <span className="font-medium">
                                        v{currentSourceVersion}
                                    </span>
                                </div>
                            ) : null}
                        </div>

                        {'action' in form.errors && form.errors.action ? (
                            <p className="text-sm text-destructive">
                                {String(form.errors.action)}
                            </p>
                        ) : null}

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
                                disabled={
                                    form.processing || resolvedManager === null
                                }
                                onClick={submit}
                            >
                                {form.processing
                                    ? 'Assigning…'
                                    : 'Assign manager countersignature'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
