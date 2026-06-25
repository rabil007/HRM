import { useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { cn } from '@/lib/utils';
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';
import {
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

export function ReplaceDocumentDialog({
    document,
    employeeId,
    onOpenChange,
    templateFields = null,
    partialReloadKeys = ['documents'],
}: {
    document: DocumentProfileItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
    templateFields?: Record<string, TemplateFieldConfig> | null;
    partialReloadKeys?: string[];
}): ReactElement {
    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        clearMissingRequired,
        validateRequired,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
    });

    const replaceForm = useForm({
        file: null as File | null,
        document_number: document?.document_number ?? '',
        issue_date: document?.issue_date ?? '',
        expiry_date: document?.expiry_date ?? '',
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

    useEffect(() => {
        if (!document) {
            return;
        }

        replaceForm.setData({
            file: null,
            document_number: document.document_number ?? '',
            issue_date: document.issue_date ?? '',
            expiry_date: document.expiry_date ?? '',
        });
        replaceForm.clearErrors();
        clearMissingRequired();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when opening a different document
    }, [document?.id]);

    const handleOpenChange = (open: boolean): void => {
        if (!open && isBusy) {
            return;
        }

        if (!open) {
            clearMissingRequired();
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
                <div className="space-y-4 py-2">
                    <p className="text-sm text-muted-foreground">
                        The current file will be kept in version history. Images are compressed in
                        your browser. PDFs larger than 5 MB are optimized on the server after
                        upload.
                    </p>

                    <div className="space-y-1.5">
                        <Label>File <RequiredIndicator show={true} /></Label>
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

                    {showField('document_number') || showField('issue_date') || showField('expiry_date') ? (
                        <div className="grid gap-4 border-t border-border/40 pt-4">
                            {showField('document_number') ? (
                                <RecordFormField
                                    field="document_number"
                                    highlightMissing={isMissingRequired('document_number')}
                                >
                                    <div className="space-y-1.5">
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('document_number'),
                                            )}
                                        >
                                            Document Number
                                            <RequiredIndicator
                                                show={isFieldRequired('document_number')}
                                            />
                                        </Label>
                                        <Input
                                            className={cn(
                                                recordFieldInputClass(
                                                    isMissingRequired('document_number'),
                                                ),
                                                'h-10 rounded-xl border-border/60 bg-muted/50 font-mono text-sm',
                                            )}
                                            placeholder="e.g. A12345678"
                                            value={replaceForm.data.document_number}
                                            onChange={(e) =>
                                                replaceForm.setData('document_number', e.target.value)
                                            }
                                        />
                                        {replaceForm.errors.document_number ? (
                                            <p className="text-xs text-destructive">
                                                {replaceForm.errors.document_number}
                                            </p>
                                        ) : null}
                                    </div>
                                </RecordFormField>
                            ) : null}

                            {showField('issue_date') || showField('expiry_date') ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {showField('issue_date') ? (
                                        <RecordFormField
                                            field="issue_date"
                                            highlightMissing={isMissingRequired('issue_date')}
                                        >
                                            <div className="space-y-1.5">
                                                <Label
                                                    className={recordFieldLabelClass(
                                                        isMissingRequired('issue_date'),
                                                    )}
                                                >
                                                    Issue Date
                                                    <RequiredIndicator
                                                        show={isFieldRequired('issue_date')}
                                                    />
                                                </Label>
                                                <Input
                                                    type="date"
                                                    className={cn(
                                                        recordFieldInputClass(
                                                            isMissingRequired('issue_date'),
                                                        ),
                                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                    )}
                                                    value={replaceForm.data.issue_date}
                                                    onChange={(e) =>
                                                        replaceForm.setData('issue_date', e.target.value)
                                                    }
                                                />
                                                {replaceForm.errors.issue_date ? (
                                                    <p className="text-xs text-destructive">
                                                        {replaceForm.errors.issue_date}
                                                    </p>
                                                ) : null}
                                            </div>
                                        </RecordFormField>
                                    ) : null}

                                    {showField('expiry_date') ? (
                                        <RecordFormField
                                            field="expiry_date"
                                            highlightMissing={isMissingRequired('expiry_date')}
                                        >
                                            <div className="space-y-1.5">
                                                <Label
                                                    className={recordFieldLabelClass(
                                                        isMissingRequired('expiry_date'),
                                                    )}
                                                >
                                                    Expiry Date
                                                    <RequiredIndicator
                                                        show={isFieldRequired('expiry_date')}
                                                    />
                                                </Label>
                                                <Input
                                                    type="date"
                                                    className={cn(
                                                        recordFieldInputClass(
                                                            isMissingRequired('expiry_date'),
                                                        ),
                                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                    )}
                                                    value={replaceForm.data.expiry_date}
                                                    onChange={(e) =>
                                                        replaceForm.setData('expiry_date', e.target.value)
                                                    }
                                                />
                                                {replaceForm.errors.expiry_date ? (
                                                    <p className="text-xs text-destructive">
                                                        {replaceForm.errors.expiry_date}
                                                    </p>
                                                ) : null}
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
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

                            if (
                                !validateRequired({
                                    document_number: replaceForm.data.document_number,
                                    issue_date: replaceForm.data.issue_date,
                                    expiry_date: replaceForm.data.expiry_date,
                                })
                            ) {
                                return;
                            }

                            replaceForm.clearErrors();
                            replaceForm.transform((data) => ({
                                ...data,
                                document_number: data.document_number.trim() || null,
                                issue_date: data.issue_date || null,
                                expiry_date: data.expiry_date || null,
                            }));

                            replaceForm.post(
                                EmployeeDocumentController.replace.url({
                                    employee: employeeId,
                                    document: document.id,
                                }),
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    only: partialReloadKeys,
                                    onSuccess: () => {
                                        clearMissingRequired();
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
