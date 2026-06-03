import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DocumentProfileItem } from '@/features/organization/documents/shared/types';
import { compressUploadFile } from '@/features/organization/documents/upload/compress-upload-file';
import {
    DocumentUploadProgressOverlay,
    resolveDocumentUploadPhase,
} from '@/features/organization/documents/upload/document-upload-progress';
import {
    formatUploadFileSize,
} from '@/features/organization/documents/upload/upload-draft';
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';

export function ReplaceDocumentDialog({
    document,
    employeeId,
    onOpenChange,
}: {
    document: DocumentProfileItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
}): ReactElement {
    const replaceForm = useForm({
        file: null as File | null,
    });
    const [isPreparingFile, setIsPreparingFile] = useState(false);

    const uploadProgress = replaceForm.progress
        ? {
              percentage: replaceForm.progress.percentage ?? 0,
              loaded: replaceForm.progress.loaded,
              total: replaceForm.progress.total,
          }
        : null;

    const uploadProgressPhase = resolveDocumentUploadPhase({
        isPreparing: isPreparingFile,
        isUploading: replaceForm.processing,
        progress: uploadProgress,
    });

    const isBusy = uploadProgressPhase !== null;

    const handleOpenChange = (open: boolean): void => {
        if (!open && isBusy) {
            return;
        }

        onOpenChange(open);
    };

    const handleFileChange = async (file: File | null) => {
        if (!file) {
            replaceForm.setData('file', null);

            return;
        }

        setIsPreparingFile(true);

        try {
            const prepared = await compressUploadFile(file);
            replaceForm.setData('file', prepared);
        } catch {
            toast.error('Could not prepare the selected file.');
            replaceForm.setData('file', null);
        } finally {
            setIsPreparingFile(false);
        }
    };

    return (
        <Dialog open={!!document} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <div className="relative">
                    <DocumentUploadProgressOverlay
                        open={isBusy}
                        phase={uploadProgressPhase ?? 'uploading'}
                        progress={uploadProgress}
                        fileLabel={replaceForm.data.file?.name ?? null}
                    />
                <DialogHeader>
                    <DialogTitle>Replace Document File</DialogTitle>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <p className="text-sm text-muted-foreground">
                        The current file will be kept in version history. Images are compressed in
                        your browser. PDFs larger than 5 MB are optimized on the server after
                        upload.
                    </p>
                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        disabled={isBusy}
                        onChange={(event) => {
                            void handleFileChange(event.target.files?.[0] ?? null);
                            event.currentTarget.value = '';
                        }}
                        className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                    />
                    {isPreparingFile && !replaceForm.processing ? (
                        <p className="text-xs text-muted-foreground">Optimizing image…</p>
                    ) : null}
                    {replaceForm.data.file ? (
                        <p className="text-xs text-muted-foreground">
                            Selected: {replaceForm.data.file.name} (
                            {formatUploadFileSize(replaceForm.data.file.size)})
                        </p>
                    ) : null}
                    {replaceForm.errors.file ? (
                        <p className="text-xs text-destructive">{replaceForm.errors.file}</p>
                    ) : null}
                </div>
                <DialogFooter className="border-t border-border/60 pt-4">
                    <Button
                        variant="outline"
                        size="sm"
                        className={actions.dialogSecondary}
                        disabled={isBusy}
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        className={actions.dialogPrimary}
                        disabled={isBusy || !replaceForm.data.file}
                        onClick={() => {
                            if (!document) {
                                return;
                            }

                            replaceForm.post(
                                EmployeeDocumentController.replace.url({
                                    employee: employeeId,
                                    document: document.id,
                                }),
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    only: ['documents'],
                                    onSuccess: () => {
                                        onOpenChange(false);
                                        replaceForm.reset();
                                    },
                                },
                            );
                        }}
                    >
                        {replaceForm.processing
                            ? uploadProgress?.percentage
                                ? `Replacing ${uploadProgress.percentage}%…`
                                : 'Replacing…'
                            : isPreparingFile
                              ? 'Preparing…'
                              : 'Replace'}
                    </Button>
                </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
