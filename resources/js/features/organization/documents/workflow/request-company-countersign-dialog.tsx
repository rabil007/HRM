import { useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import CreateDocumentCompanyCountersignRequestController from '@/actions/App/Http/Controllers/Organization/Documents/CreateDocumentCompanyCountersignRequestController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SignatoryOption } from '@/features/organization/documents/workflow/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    documentId: number;
    employeeName: string;
    documentTitle: string;
    currentSourceVersion: number | null | undefined;
    signatoryOptions: SignatoryOption[];
};

type FlashPayload = {
    id: number;
    recipient_name: string;
    respond_url: string;
};

export function RequestCompanyCountersignDialog({
    open,
    onOpenChange,
    employeeId,
    documentId,
    employeeName,
    documentTitle,
    currentSourceVersion,
    signatoryOptions,
}: Props) {
    const [success, setSuccess] = useState<FlashPayload | null>(null);
    const [copied, setCopied] = useState(false);

    const form = useForm({
        recipient_user_id: '',
    });

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            setSuccess(null);
            setCopied(false);
            form.reset();
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
            CreateDocumentCompanyCountersignRequestController.url({
                employee: employeeId,
                document: documentId,
            }),
            {
                preserveScroll: true,
                onSuccess: (responsePage) => {
                    const payload = (
                        responsePage.props as {
                            flash?: {
                                company_countersign_request_created?: FlashPayload;
                            };
                        }
                    ).flash?.company_countersign_request_created;

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
                    <DialogTitle>Request company countersignature</DialogTitle>
                    <DialogDescription>
                        Assign an authenticated company user to countersign the
                        employee-signed document version.
                    </DialogDescription>
                </DialogHeader>

                {success ? (
                    <div className="space-y-4">
                        <div className="rounded-xl border bg-muted/20 p-4 text-sm">
                            <p className="font-medium">
                                Company countersignature assigned to{' '}
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
                                    Company signatory
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

                        <div className="space-y-2">
                            <Label>Company signatory</Label>
                            <Select
                                value={form.data.recipient_user_id}
                                onValueChange={(value) =>
                                    form.setData('recipient_user_id', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select authorized user" />
                                </SelectTrigger>
                                <SelectContent>
                                    {signatoryOptions.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.name}
                                            {option.email
                                                ? ` (${option.email})`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.recipient_user_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.recipient_user_id}
                                </p>
                            ) : null}
                            {'action' in form.errors && form.errors.action ? (
                                <p className="text-sm text-destructive">
                                    {String(form.errors.action)}
                                </p>
                            ) : null}
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
                                disabled={
                                    form.processing ||
                                    !form.data.recipient_user_id
                                }
                                onClick={submit}
                            >
                                {form.processing
                                    ? 'Assigning…'
                                    : 'Assign countersignature'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
