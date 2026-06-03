import { router } from '@inertiajs/react';
import { FileText, UploadCloud } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
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
import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import { UploadDocumentDraftForm } from '@/features/organization/documents/upload/upload-document-draft-form';
import { UploadDocumentDraftListItem } from '@/features/organization/documents/upload/upload-document-draft-list-item';
import {
    isSupportedUploadFile,
    prepareUploadFiles,
} from '@/features/organization/documents/upload/compress-upload-file';
import {
    copyMetadataFromSource,
    createUploadDraftFromFile,
    fileMatchesExistingDraft,
    firstInvalidDraftIndex,
    formatUploadFileSize,
    MAX_UPLOAD_FILES,
    PDF_COMPRESS_THRESHOLD_LABEL,
    parseBulkDocumentErrors,
} from '@/features/organization/documents/upload/upload-draft';
import type {
    UploadDraft,
    UploadDraftFieldErrors,
    UploadDraftMetadata,
} from '@/features/organization/documents/upload/upload-draft';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import {
    getTemplateRequiredFieldKeys,
    isEmptyTemplateFieldValue,
} from '@/pages/organization/_lib/template-field-visibility';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

function uploadDraftToFormData(draft: UploadDraft): Record<string, unknown> {
    return {
        document_type_id: draft.document_type_id,
        title: draft.title.trim() || null,
        document_number: draft.document_number.trim() || null,
        issue_date: draft.issue_date || null,
        expiry_date: draft.expiry_date || null,
        notes: draft.notes.trim() || null,
    };
}

const DOCUMENTS_RELOAD = {
    preserveScroll: true,
    only: ['documents'],
} as const;

export function UploadDocumentDialog({
    open,
    onOpenChange,
    employeeId,
    employeeName,
    documentTypes,
    ensureEmployee,
    templateFields = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number | null;
    employeeName: string;
    documentTypes: DocumentTypeOption[];
    ensureEmployee?: () => Promise<number>;
    templateFields?: Record<string, TemplateFieldConfig> | null;
}): ReactElement {
    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
    });

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(
                templateFields,
                TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
            ),
        [templateFields],
    );

    const draftMeetsRequired = useCallback(
        (draft: UploadDraft): boolean => {
            if (draft.document_type_id.trim() === '') {
                return false;
            }

            const data = uploadDraftToFormData(draft);

            for (const field of requiredFields) {
                if (!showField(field)) {
                    continue;
                }

                if (isEmptyTemplateFieldValue(data[field])) {
                    return false;
                }
            }

            return true;
        },
        [requiredFields, showField],
    );

    const [drafts, setDrafts] = useState<UploadDraft[]>([]);
    const [selectedDraftId, setSelectedDraftId] = useState<string | null>(null);
    const [fieldErrorsByIndex, setFieldErrorsByIndex] = useState<
        Map<number, UploadDraftFieldErrors>
    >(new Map());
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);
    const [isCompressingFiles, setIsCompressingFiles] = useState(false);
    const [isUploading, setIsUploading] = useState(false);

    const selectedDraft = useMemo(
        () => drafts.find((draft) => draft.id === selectedDraftId) ?? null,
        [drafts, selectedDraftId],
    );

    const selectedDraftFormData = useMemo(
        () => (selectedDraft ? uploadDraftToFormData(selectedDraft) : {}),
        [selectedDraft],
    );

    useClearMissingOnFormChange(selectedDraftFormData, syncMissingFromFormData);

    const selectedDraftIndex = useMemo(
        () => drafts.findIndex((draft) => draft.id === selectedDraftId),
        [drafts, selectedDraftId],
    );

    const resetUploadDialog = useCallback(() => {
        setDrafts([]);
        setSelectedDraftId(null);
        setFieldErrorsByIndex(new Map());
        setIsDraggingFiles(false);
        setIsCompressingFiles(false);
        setIsUploading(false);
        clearMissingRequired();
    }, [clearMissingRequired]);

    const addUploadFiles = useCallback(async (files: File[]) => {
        const supportedFiles = files.filter((file) => isSupportedUploadFile(file));

        if (supportedFiles.length !== files.length) {
            toast.error('Only PDF, JPG, JPEG, and PNG files are supported.');
        }

        if (supportedFiles.length === 0) {
            return;
        }

        setIsCompressingFiles(true);

        let preparedFiles = supportedFiles;

        try {
            preparedFiles = await prepareUploadFiles(supportedFiles);
        } catch {
            toast.error('Could not prepare one or more files for upload.');
        } finally {
            setIsCompressingFiles(false);
        }

        setDrafts((current) => {
            const next = [...current];
            let addedId: string | null = null;

            for (const file of preparedFiles) {
                if (next.length >= MAX_UPLOAD_FILES) {
                    toast.error(`You can upload up to ${MAX_UPLOAD_FILES} files at once.`);

                    break;
                }

                if (fileMatchesExistingDraft(next, file)) {
                    continue;
                }

                const draft = createUploadDraftFromFile(file);
                next.push(draft);
                addedId = draft.id;
            }

            if (addedId) {
                setSelectedDraftId(addedId);
            }

            return next;
        });
    }, []);

    const removeDraft = useCallback((draftId: string) => {
        setDrafts((current) => {
            const removedIndex = current.findIndex((draft) => draft.id === draftId);
            const next = current.filter((draft) => draft.id !== draftId);

            if (removedIndex !== -1) {
                setFieldErrorsByIndex((errors) => {
                    const remapped = new Map<number, UploadDraftFieldErrors>();

                    errors.forEach((value, index) => {
                        if (index === removedIndex) {
                            return;
                        }

                        remapped.set(index > removedIndex ? index - 1 : index, value);
                    });

                    return remapped;
                });
            }

            setSelectedDraftId((selectedId) => {
                if (selectedId !== draftId) {
                    return selectedId;
                }

                return next[0]?.id ?? null;
            });

            return next;
        });
    }, []);

    const updateDraft = useCallback(
        (draftId: string, patch: Partial<UploadDraftMetadata>) => {
            setDrafts((current) => {
                const index = current.findIndex((draft) => draft.id === draftId);

                if (index !== -1) {
                    setFieldErrorsByIndex((errors) => {
                        if (!errors.has(index)) {
                            return errors;
                        }

                        const next = new Map(errors);
                        const existing = { ...next.get(index)! };
                        const field = Object.keys(patch)[0] as keyof UploadDraftFieldErrors;

                        if (field && existing[field]) {
                            delete existing[field];
                        }

                        if (Object.keys(existing).length === 0) {
                            next.delete(index);
                        } else {
                            next.set(index, existing);
                        }

                        return next;
                    });
                }

                return current.map((draft) =>
                    draft.id === draftId ? { ...draft, ...patch } : draft,
                );
            });
        },
        [],
    );

    const applyMetadataToAll = useCallback(() => {
        if (!selectedDraft || drafts.length < 2) {
            return;
        }

        const metadata = copyMetadataFromSource(selectedDraft);

        setDrafts((current) =>
            current.map((draft) =>
                draft.id === selectedDraft.id ? draft : { ...draft, ...metadata },
            ),
        );
    }, [drafts.length, selectedDraft]);

    const uploadFileSize = useMemo(() => {
        return drafts.reduce((total, draft) => total + draft.file.size, 0);
    }, [drafts]);

    const canUpload =
        drafts.length > 0 &&
        !isUploading &&
        drafts.every((draft) => draftMeetsRequired(draft));

    const submitUpload = useCallback(async () => {
        if (drafts.length === 0 || isUploading) {
            return;
        }

        for (const draft of drafts) {
            if (!validateRequired(uploadDraftToFormData(draft))) {
                setSelectedDraftId(draft.id);

                return;
            }
        }

        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        setIsUploading(true);
        setFieldErrorsByIndex(new Map());

        router.post(
            EmployeeDocumentController.bulkStore.url({ employee: resolvedEmployeeId }),
            {
                documents: drafts.map((draft) => ({
                    document_type_id: Number(draft.document_type_id),
                    title: draft.title.trim() || draft.file.name,
                    file: draft.file,
                    document_number: draft.document_number || null,
                    issue_date: draft.issue_date || null,
                    expiry_date: draft.expiry_date || null,
                    notes: draft.notes || null,
                })),
            },
            {
                forceFormData: true,
                ...DOCUMENTS_RELOAD,
                onSuccess: () => {
                    onOpenChange(false);
                    resetUploadDialog();
                },
                onError: (errors) => {
                    const parsed = parseBulkDocumentErrors(
                        errors as Record<string, string | string[]>,
                    );
                    setFieldErrorsByIndex(parsed);

                    const firstInvalid = firstInvalidDraftIndex(parsed);

                    if (firstInvalid !== null && drafts[firstInvalid]) {
                        setSelectedDraftId(drafts[firstInvalid].id);
                    }
                },
                onFinish: () => setIsUploading(false),
            },
        );
    }, [drafts, employeeId, ensureEmployee, isUploading, onOpenChange, resetUploadDialog, validateRequired]);

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                onOpenChange(nextOpen);

                if (!nextOpen) {
                    resetUploadDialog();
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Upload Employee Documents</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        Add one or many files for {employeeName}. Select a file on the left, then
                        enter its details on the right.
                    </p>
                </DialogHeader>

                <EmployeeMissingRequiredFieldsAlert
                    missingFields={missingRequiredFieldsList}
                    onFocusField={focusMissingField}
                />

                <div className="grid gap-5 py-2 lg:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-4">
                        <div
                            onDragOver={(event) => {
                                event.preventDefault();
                                setIsDraggingFiles(true);
                            }}
                            onDragLeave={() => setIsDraggingFiles(false)}
                            onDrop={(event) => {
                                event.preventDefault();
                                setIsDraggingFiles(false);
                                void addUploadFiles(Array.from(event.dataTransfer.files));
                            }}
                            className={`rounded-2xl border border-dashed p-6 transition-colors ${
                                isDraggingFiles
                                    ? 'border-primary bg-primary/10'
                                    : 'border-border bg-muted/20 hover:bg-muted/30'
                            }`}
                        >
                            <div className="flex flex-col items-center gap-3 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                    <UploadCloud className="h-6 w-6" />
                                </div>
                                <div>
                                    <div className="text-sm font-semibold">Drag and drop files here</div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Upload up to {MAX_UPLOAD_FILES} files. Supported formats: PDF,
                                        JPG, JPEG, PNG. Images are compressed in your browser. PDFs
                                        larger than {PDF_COMPRESS_THRESHOLD_LABEL} are optimized on
                                        the server.
                                    </div>
                                </div>
                                {isCompressingFiles ? (
                                    <p className="text-xs text-muted-foreground">
                                        Optimizing images…
                                    </p>
                                ) : null}
                                <label className="inline-flex cursor-pointer items-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90">
                                    Browse files
                                    <input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        multiple
                                        disabled={isCompressingFiles || isUploading}
                                        className="sr-only"
                                        onChange={(event) => {
                                            void addUploadFiles(Array.from(event.target.files ?? []));
                                            event.currentTarget.value = '';
                                        }}
                                    />
                                </label>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-border bg-card/40">
                            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                                <div>
                                    <div className="text-sm font-semibold">Selected files</div>
                                    <div className="text-xs text-muted-foreground">
                                        {drafts.length} file(s), {formatUploadFileSize(uploadFileSize)}{' '}
                                        total
                                    </div>
                                </div>
                                {drafts.length > 0 ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setDrafts([]);
                                            setSelectedDraftId(null);
                                            setFieldErrorsByIndex(new Map());
                                        }}
                                    >
                                        Clear
                                    </Button>
                                ) : null}
                            </div>
                            <div className="max-h-56 space-y-2 overflow-y-auto p-3">
                                {drafts.length === 0 ? (
                                    <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                        No files selected yet.
                                    </div>
                                ) : (
                                    drafts.map((draft, index) => (
                                        <UploadDocumentDraftListItem
                                            key={draft.id}
                                            draft={draft}
                                            index={index}
                                            documentTypes={documentTypes}
                                            selected={selectedDraftId === draft.id}
                                            hasErrors={fieldErrorsByIndex.has(index)}
                                            onSelect={() => setSelectedDraftId(draft.id)}
                                            onRemove={() => removeDraft(draft.id)}
                                        />
                                    ))
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-border bg-card/40 p-4">
                        {selectedDraft ? (
                            <UploadDocumentDraftForm
                                draft={selectedDraft}
                                documentTypes={documentTypes}
                                onChange={(patch) => updateDraft(selectedDraft.id, patch)}
                                fieldErrors={
                                    selectedDraftIndex >= 0
                                        ? fieldErrorsByIndex.get(selectedDraftIndex)
                                        : undefined
                                }
                                showApplyToAll={drafts.length > 1}
                                onApplyToAll={applyMetadataToAll}
                                showField={showField}
                                isFieldRequired={isFieldRequired}
                                isMissingRequired={isMissingRequired}
                            />
                        ) : (
                            <div className="flex h-full min-h-[280px] flex-col items-center justify-center gap-3 px-4 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <FileText className="h-6 w-6" />
                                </div>
                                <div className="text-sm font-semibold">Select a file</div>
                                <p className="max-w-xs text-xs text-muted-foreground">
                                    Choose a file from the list to set its document type, title,
                                    dates, and notes.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter className="items-center border-t border-border/60 pt-4 sm:justify-between">
                    <div className="text-xs text-muted-foreground">
                        {drafts.length === 0
                            ? 'Select at least one file to upload.'
                            : drafts.length > 1
                              ? 'Bulk upload will create one document record per file.'
                              : 'One document will be created.'}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            className={actions.dialogSecondary}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            className={actions.dialogPrimary}
                            disabled={!canUpload}
                            onClick={submitUpload}
                        >
                            {isUploading
                                ? 'Uploading…'
                                : `Upload ${drafts.length || ''}`.trim()}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
