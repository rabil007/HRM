import { router, useForm } from '@inertiajs/react';
import { FileText, UploadCloud, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import { toast } from '@/lib/toast';

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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    employeeName: string;
    documentTypes: DocumentTypeOption[];
}): ReactElement {
    const [bulkFiles, setBulkFiles] = useState<File[]>([]);
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);

    const uploadForm = useForm({
        document_type_id: '',
        title: '',
        file: null as File | null,
        issue_date: '',
        expiry_date: '',
        document_number: '',
        notes: '',
    });

    const addUploadFiles = useCallback(
        (files: File[]) => {
            const supportedFiles = files.filter((file) => {
                return ['application/pdf', 'image/jpeg', 'image/png'].includes(file.type);
            });

            setBulkFiles((current) => {
                const next = [...current];

                supportedFiles.forEach((file) => {
                    const exists = next.some((item) => {
                        return (
                            item.name === file.name &&
                            item.size === file.size &&
                            item.lastModified === file.lastModified
                        );
                    });

                    if (!exists) {
                        next.push(file);
                    }
                });

                uploadForm.setData('file', next[0] ?? null);

                return next;
            });

            if (supportedFiles.length !== files.length) {
                toast.error('Only PDF, JPG, JPEG, and PNG files are supported.');
            }
        },
        [uploadForm],
    );

    const removeUploadFile = useCallback(
        (fileIndex: number) => {
            setBulkFiles((current) => {
                const next = current.filter((_, index) => index !== fileIndex);
                uploadForm.setData('file', next[0] ?? null);

                return next;
            });
        },
        [uploadForm],
    );

    const resetUploadDialog = useCallback(() => {
        uploadForm.reset();
        uploadForm.clearErrors();
        setBulkFiles([]);
        setIsDraggingFiles(false);
    }, [uploadForm]);

    const uploadFileSize = useMemo(() => {
        return bulkFiles.reduce((total, file) => total + file.size, 0);
    }, [bulkFiles]);

    const formatFileSize = (bytes: number): string => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }

        if (bytes < 1024 * 1024) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }

        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

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
                        Add one or many files for {employeeName}. Shared details below will be applied to every selected file.
                    </p>
                </DialogHeader>

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
                                addUploadFiles(Array.from(event.dataTransfer.files));
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
                                        Upload up to 20 files. Supported formats: PDF, JPG, JPEG, PNG. Max 20 MB each.
                                    </div>
                                </div>
                                <label className="inline-flex cursor-pointer items-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90">
                                    Browse files
                                    <input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        multiple
                                        className="sr-only"
                                        onChange={(event) => {
                                            addUploadFiles(Array.from(event.target.files ?? []));
                                            event.currentTarget.value = '';
                                        }}
                                    />
                                </label>
                                {uploadForm.errors.file ? (
                                    <p className="text-xs text-destructive">{uploadForm.errors.file}</p>
                                ) : null}
                            </div>
                        </div>

                        <div className="rounded-2xl border border-border bg-card/40">
                            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                                <div>
                                    <div className="text-sm font-semibold">Selected files</div>
                                    <div className="text-xs text-muted-foreground">
                                        {bulkFiles.length} file(s), {formatFileSize(uploadFileSize)} total
                                    </div>
                                </div>
                                {bulkFiles.length > 0 ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setBulkFiles([]);
                                            uploadForm.setData('file', null);
                                        }}
                                    >
                                        Clear
                                    </Button>
                                ) : null}
                            </div>
                            <div className="max-h-56 space-y-2 overflow-y-auto p-3">
                                {bulkFiles.length === 0 ? (
                                    <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                        No files selected yet.
                                    </div>
                                ) : (
                                    bulkFiles.map((file) => (
                                        <div
                                            key={`${file.name}-${file.size}-${file.lastModified}`}
                                            className="flex items-center justify-between gap-3 rounded-xl border border-border bg-background px-3 py-2"
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                    <FileText className="h-4 w-4" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-medium">{file.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {file.type || 'Unknown type'} · {formatFileSize(file.size)}
                                                    </div>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                onClick={() => removeUploadFile(bulkFiles.indexOf(file))}
                                            >
                                                <X className="h-4 w-4" />
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-border bg-card/40 p-4">
                        <div>
                            <div className="text-sm font-semibold">Document information</div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                These values will be saved with every selected file. Leave title empty to use each file name.
                            </p>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs">
                                Document Type <span className="text-destructive">*</span>
                            </Label>
                            <AppSelect
                                value={uploadForm.data.document_type_id}
                                onValueChange={(v) => uploadForm.setData('document_type_id', v)}
                                variant="card"
                                placeholder="Select type…"
                            >
                                <AppSelectItem value="">Select type…</AppSelectItem>
                                {documentTypes.map((type) => (
                                    <AppSelectItem key={type.id} value={String(type.id)}>
                                        {type.title}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {uploadForm.errors.document_type_id ? (
                                <p className="text-xs text-destructive">{uploadForm.errors.document_type_id}</p>
                            ) : null}
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs">Title</Label>
                            <Input
                                className="h-10 text-sm"
                                placeholder="e.g. Passport Copy"
                                value={uploadForm.data.title}
                                onChange={(event) => uploadForm.setData('title', event.target.value)}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs">Document Number</Label>
                            <Input
                                className="h-10 text-sm"
                                placeholder="e.g. A123456"
                                value={uploadForm.data.document_number}
                                onChange={(event) => uploadForm.setData('document_number', event.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Issue Date</Label>
                                <Input
                                    type="date"
                                    className="h-10 text-sm"
                                    value={uploadForm.data.issue_date}
                                    onChange={(event) => uploadForm.setData('issue_date', event.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">Expiry Date</Label>
                                <Input
                                    type="date"
                                    className="h-10 text-sm"
                                    value={uploadForm.data.expiry_date}
                                    onChange={(event) => uploadForm.setData('expiry_date', event.target.value)}
                                />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs">Notes</Label>
                            <textarea
                                rows={4}
                                className="w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary"
                                placeholder="Optional notes, renewal reminders, or source details…"
                                value={uploadForm.data.notes}
                                onChange={(event) => uploadForm.setData('notes', event.target.value)}
                            />
                        </div>
                    </div>
                </div>

                <DialogFooter className="items-center border-t border-white/5 pt-4 sm:justify-between">
                    <div className="text-xs text-zinc-500">
                        {bulkFiles.length > 1
                            ? 'Bulk upload will create one document record per file.'
                            : 'Select at least one file to upload.'}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={
                                uploadForm.processing ||
                                bulkFiles.length === 0 ||
                                !uploadForm.data.document_type_id
                            }
                            onClick={() => {
                                if (bulkFiles.length > 1) {
                                    router.post(
                                        EmployeeDocumentController.bulkStore.url({ employee: employeeId }),
                                        {
                                            documents: bulkFiles.map((file) => ({
                                                document_type_id: uploadForm.data.document_type_id,
                                                title: uploadForm.data.title || file.name,
                                                file,
                                                document_number: uploadForm.data.document_number,
                                                issue_date: uploadForm.data.issue_date,
                                                expiry_date: uploadForm.data.expiry_date,
                                                notes: uploadForm.data.notes,
                                            })),
                                        },
                                        {
                                            forceFormData: true,
                                            ...DOCUMENTS_RELOAD,
                                            onSuccess: () => {
                                                onOpenChange(false);
                                                resetUploadDialog();
                                                toast.success('Documents uploaded.');
                                            },
                                        },
                                    );

                                    return;
                                }

                                uploadForm.post(
                                    EmployeeDocumentController.store.url({ employee: employeeId }),
                                    {
                                        forceFormData: true,
                                        onSuccess: () => {
                                            onOpenChange(false);
                                            resetUploadDialog();
                                            toast.success('Document uploaded.');
                                        },
                                    },
                                );
                            }}
                        >
                            {uploadForm.processing ? 'Uploading…' : `Upload ${bulkFiles.length || ''}`.trim()}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
