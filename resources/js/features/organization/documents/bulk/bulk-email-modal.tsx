import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
import type { BulkEmailTemplateOption } from '@/features/organization/documents/bulk/types';
import { toast } from '@/lib/toast';

export function BulkDocumentsEmailModal({
    open,
    onOpenChange,
    documentTypeKey,
    employeeIds,
    emailTemplates,
    onSendComplete,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    documentTypeKey: string;
    employeeIds: number[];
    emailTemplates: BulkEmailTemplateOption[];
    onSendComplete: () => void;
}) {
    const defaultTemplate = useMemo(
        () =>
            emailTemplates.find((template) => template.is_default) ??
            emailTemplates[0] ??
            null,
        [emailTemplates],
    );

    const [templateId, setTemplateId] = useState<string>('');
    const [isSending, setIsSending] = useState(false);

    useEffect(() => {
        if (open && defaultTemplate) {
            setTemplateId(String(defaultTemplate.id));
        }
    }, [open, defaultTemplate]);

    const selectedTemplate =
        emailTemplates.find((template) => String(template.id) === templateId) ??
        defaultTemplate;

    const handleSend = () => {
        if (employeeIds.length === 0 || !selectedTemplate) {
            return;
        }

        setIsSending(true);

        router.post(
            '/organization/documents/bulk/email',
            {
                document_type_key: documentTypeKey,
                employee_ids: employeeIds,
                email_template_id: selectedTemplate.id,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onSendComplete();
                    toast.success(
                        `Email queued for ${employeeIds.length} employee(s).`,
                    );
                },
                onFinish: () => setIsSending(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Send to {employeeIds.length} employee
                        {employeeIds.length === 1 ? '' : 's'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label>Email template</Label>
                        <Select value={templateId} onValueChange={setTemplateId}>
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a template" />
                            </SelectTrigger>
                            <SelectContent>
                                {emailTemplates.map((template) => (
                                    <SelectItem
                                        key={template.id}
                                        value={String(template.id)}
                                    >
                                        {template.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <a
                            href="/settings/application/email-templates"
                            target="_blank"
                            rel="noreferrer"
                            className="text-xs text-primary hover:underline"
                        >
                            Manage templates
                        </a>
                    </div>

                    {selectedTemplate ? (
                        <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                            <p className="font-medium">{selectedTemplate.subject}</p>
                            <div
                                className="prose prose-sm mt-2 max-w-none dark:prose-invert"
                                dangerouslySetInnerHTML={{
                                    __html: selectedTemplate.body_html,
                                }}
                            />
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSend}
                        disabled={
                            isSending ||
                            employeeIds.length === 0 ||
                            !selectedTemplate
                        }
                    >
                        {isSending ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : null}
                        Send to {employeeIds.length} employee
                        {employeeIds.length === 1 ? '' : 's'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
