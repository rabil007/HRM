import { router } from '@inertiajs/react';
import {
    Download,
    FileText,
    FileUp,
    Loader2,
    Paperclip,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import * as RequirementAttachmentController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementAttachmentController';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { toast } from '@/lib/toast';
import type {
    RequirementAttachment,
    RequirementDetail,
} from '@/types/recruitment';

type Props = {
    requirement: RequirementDetail;
    canDownload: boolean;
};

export function RequirementAttachmentsCard({
    requirement,
    canDownload,
}: Props) {
    const [isUploading, setIsUploading] = useState(false);
    const attachments = requirement.attachments || [];

    const handleUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        setIsUploading(true);
        router.post(
            RequirementController.update.url(requirement.id),
            {
                _method: 'PUT',
                attachment: file,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Attachment uploaded.');
                    setIsUploading(false);
                },
                onError: () => {
                    toast.error(
                        'Failed to upload attachment. Check file size (max 10MB).',
                    );
                    setIsUploading(false);
                },
            },
        );
    };

    const handleDelete = (attachment: RequirementAttachment) => {
        if (
            !confirm(
                `Are you sure you want to delete "${attachment.original_file_name}"?`,
            )
        ) {
            return;
        }

        router.delete(
            RequirementAttachmentController.destroy.url({
                requirement: requirement.id,
                attachment: attachment.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Attachment deleted.'),
                onError: () => toast.error('Failed to delete attachment.'),
            },
        );
    };

    return (
        <Card className="glass-card border-border/70">
            <CardHeader className="border-b border-border/40 pb-4">
                <div className="flex items-center justify-between">
                    <CardTitle className="flex items-center gap-2 text-base font-bold">
                        <Paperclip className="h-4 w-4 text-primary" />
                        Documents & Attachments ({attachments.length})
                    </CardTitle>
                    {requirement.can_edit && (
                        <div>
                            <label
                                htmlFor="new-attachment-input"
                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border/80 bg-background/60 px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted"
                            >
                                {isUploading ? (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                ) : (
                                    <FileUp className="h-3.5 w-3.5 text-primary" />
                                )}
                                <span>Upload File</span>
                            </label>
                            <input
                                id="new-attachment-input"
                                type="file"
                                className="hidden"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.png,.jpg,.jpeg"
                                onChange={handleUpload}
                                disabled={isUploading}
                            />
                        </div>
                    )}
                </div>
            </CardHeader>
            <CardContent className="p-6">
                {attachments.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border/80 p-8 text-center text-xs text-muted-foreground">
                        No attachments uploaded for this requirement
                        requisition.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {attachments.map((att) => (
                            <div
                                key={att.id}
                                className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/20 p-3 transition-colors hover:border-border"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <FileText className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0 space-y-0.5">
                                        <p
                                            className="truncate text-xs font-semibold text-foreground"
                                            title={att.original_file_name}
                                        >
                                            {att.original_file_name}
                                        </p>
                                        <p className="text-[11px] text-muted-foreground">
                                            {att.file_size_formatted} •{' '}
                                            {att.created_at_formatted}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-1">
                                    {canDownload && (
                                        <a
                                            href={RequirementAttachmentController.download.url(
                                                {
                                                    requirement: requirement.id,
                                                    attachment: att.id,
                                                },
                                            )}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border/60 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            title="Download attachment"
                                        >
                                            <Download className="h-3.5 w-3.5" />
                                        </a>
                                    )}

                                    {requirement.can_edit && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleDelete(att)}
                                            className="h-8 w-8 p-0 text-muted-foreground hover:text-rose-500"
                                            title="Delete attachment"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
