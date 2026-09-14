import { useForm } from '@inertiajs/react';
import { Download, Trash2, Upload, UploadCloud } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type {
    DocumentWorkspaceRoutes,
    EntityDocument,
    EntityDocumentType,
} from './types';

type Metadata = {
    document_type_id: number | '';
    title: string;
    document_number: string;
    issue_date: string;
    expiry_date: string;
    notes: string;
};

type UploadData = Metadata & { file: File | null };

const FILE_ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';

const fieldLabelClass =
    'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase';

const fieldControlClass =
    'h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40';

const emptyMetadata = (): Metadata => ({
    document_type_id: '',
    title: '',
    document_number: '',
    issue_date: '',
    expiry_date: '',
    notes: '',
});

function formatFileSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(2)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function TypeSelect({
    value,
    onChange,
    documentTypes,
}: {
    value: number | '';
    onChange: (value: number | '') => void;
    documentTypes: EntityDocumentType[];
}) {
    return (
        <AppSelect
            value={value === '' ? '' : String(value)}
            onValueChange={(next) => onChange(next ? Number(next) : '')}
            variant="card"
            placeholder="Select document type"
            searchPlaceholder="Search document types..."
        >
            {documentTypes.map((type) => (
                <AppSelectItem key={type.id} value={String(type.id)}>
                    {type.title}
                </AppSelectItem>
            ))}
        </AppSelect>
    );
}

function FileDropField({
    file,
    error,
    inputId,
    onChange,
}: {
    file: File | null;
    error?: string;
    inputId: string;
    onChange: (file: File | null) => void;
}) {
    const [isDragging, setIsDragging] = useState(false);

    return (
        <div className="space-y-2">
            <Label htmlFor={inputId} className={fieldLabelClass}>
                File
                <span className="ml-1 text-destructive">*</span>
            </Label>
            {file ? (
                <div className="flex items-center gap-3 rounded-2xl border border-border bg-card px-3 py-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-muted">
                        <DocumentFileIcon
                            mimeType={file.type}
                            fileName={file.name}
                        />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">
                            {file.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {formatFileSize(file.size)}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        <label
                            htmlFor={inputId}
                            className="inline-flex h-8 cursor-pointer items-center rounded-lg border border-input bg-background px-3 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            Change
                        </label>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8 text-muted-foreground hover:text-destructive"
                            onClick={() => onChange(null)}
                            aria-label="Remove file"
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            ) : (
                <label
                    htmlFor={inputId}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setIsDragging(true);
                    }}
                    onDragLeave={() => setIsDragging(false)}
                    onDrop={(event) => {
                        event.preventDefault();
                        setIsDragging(false);
                        onChange(event.dataTransfer.files?.[0] ?? null);
                    }}
                    className={cn(
                        'flex cursor-pointer flex-col items-center gap-2.5 rounded-2xl border border-dashed px-4 py-6 text-center transition-colors',
                        isDragging
                            ? 'border-primary bg-primary/10'
                            : 'border-border bg-muted/20 hover:bg-muted/30',
                    )}
                >
                    <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                        <UploadCloud className="h-6 w-6" />
                    </div>
                    <div>
                        <p className="text-sm font-semibold">
                            Drop a file here or browse
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            PDF, JPG, JPEG, or PNG. Maximum 20 MB.
                        </p>
                    </div>
                    <span className="inline-flex items-center rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground">
                        Choose file
                    </span>
                </label>
            )}
            <input
                id={inputId}
                type="file"
                accept={FILE_ACCEPT}
                className="sr-only"
                onChange={(event) => {
                    onChange(event.target.files?.[0] ?? null);
                    event.currentTarget.value = '';
                }}
            />
            <InputError message={error} />
        </div>
    );
}

export function DocumentFormDialog({
    ownerName,
    ownerType,
    documentTypes,
    document,
    open,
    onOpenChange,
    routes,
}: {
    ownerName: string;
    ownerType: 'company' | 'branch';
    documentTypes: EntityDocumentType[];
    document: EntityDocument | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    routes: Pick<DocumentWorkspaceRoutes, 'store' | 'update'>;
}) {
    const form = useForm<UploadData>({ ...emptyMetadata(), file: null });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.setData(
            document
                ? {
                      document_type_id: document.document_type?.id ?? '',
                      title: document.title ?? '',
                      document_number: document.document_number ?? '',
                      issue_date: document.issue_date ?? '',
                      expiry_date: document.expiry_date ?? '',
                      notes: document.notes ?? '',
                      file: null,
                  }
                : { ...emptyMetadata(), file: null },
        );
        // The Inertia form object is mutable; depending on it would reset the form on every keystroke.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [document, open]);

    const submit = () => {
        if (document) {
            form.put(routes.update(document.id), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });

            return;
        }

        form.post(routes.store, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="!flex max-h-[90vh] flex-col gap-0 overflow-y-auto p-0 sm:max-w-xl">
                <DialogHeader className="sticky top-0 z-10 border-b border-border/60 bg-card/95 px-6 py-5 backdrop-blur-xl">
                    <DialogTitle>
                        {document
                            ? 'Edit document metadata'
                            : 'Upload document'}
                    </DialogTitle>
                    <DialogDescription>
                        {document
                            ? 'Update the document type, title, dates, and notes. Replace the file from the document actions if needed.'
                            : `Add a ${ownerType} file for ${ownerName}, then choose its type and details.`}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-5 px-6 py-5">
                    {!document ? (
                        <FileDropField
                            file={form.data.file}
                            error={form.errors.file}
                            inputId="entity-document-file"
                            onChange={(file) => form.setData('file', file)}
                        />
                    ) : null}

                    <div className="space-y-4">
                        <p className={actions.formSectionLabel}>
                            Document details
                        </p>
                        <div className="space-y-2">
                            <Label className={fieldLabelClass}>
                                Document type
                                <span className="ml-1 text-destructive">*</span>
                            </Label>
                            <TypeSelect
                                value={form.data.document_type_id}
                                onChange={(value) =>
                                    form.setData('document_type_id', value)
                                }
                                documentTypes={documentTypes}
                            />
                            <InputError
                                message={form.errors.document_type_id}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="entity-document-title"
                                    className={fieldLabelClass}
                                >
                                    Title
                                </Label>
                                <Input
                                    id="entity-document-title"
                                    className={fieldControlClass}
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Defaults to document type"
                                />
                                <InputError message={form.errors.title} />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="entity-document-number"
                                    className={fieldLabelClass}
                                >
                                    Document number
                                </Label>
                                <Input
                                    id="entity-document-number"
                                    className={fieldControlClass}
                                    value={form.data.document_number}
                                    onChange={(event) =>
                                        form.setData(
                                            'document_number',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Optional"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="entity-document-issue-date"
                                    className={fieldLabelClass}
                                >
                                    Issue date
                                </Label>
                                <Input
                                    id="entity-document-issue-date"
                                    type="date"
                                    className={fieldControlClass}
                                    value={form.data.issue_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'issue_date',
                                            event.target.value,
                                        )
                                    }
                                    max={form.data.expiry_date || undefined}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label
                                    htmlFor="entity-document-expiry-date"
                                    className={fieldLabelClass}
                                >
                                    Expiry date
                                </Label>
                                <Input
                                    id="entity-document-expiry-date"
                                    type="date"
                                    className={fieldControlClass}
                                    value={form.data.expiry_date}
                                    min={form.data.issue_date || undefined}
                                    onChange={(event) =>
                                        form.setData(
                                            'expiry_date',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.expiry_date} />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label
                                htmlFor="entity-document-notes"
                                className={fieldLabelClass}
                            >
                                Notes
                            </Label>
                            <Textarea
                                id="entity-document-notes"
                                className="min-h-20 rounded-xl border-border bg-card"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                                rows={2}
                                placeholder="Optional notes or renewal reminders"
                            />
                        </div>
                    </div>
                    {form.progress ? (
                        <div className="space-y-1">
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full bg-primary transition-all"
                                    style={{
                                        width: `${form.progress.percentage ?? 0}%`,
                                    }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Uploading {form.progress.percentage ?? 0}%
                            </p>
                        </div>
                    ) : null}
                </div>
                <DialogFooter className="sticky bottom-0 z-10 border-t border-border/60 bg-card/95 px-6 py-4 backdrop-blur-xl">
                    <Button
                        variant="outline"
                        className={actions.dialogSecondary}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className={actions.dialogPrimary}
                        onClick={submit}
                        disabled={form.processing}
                    >
                        {document ? 'Save changes' : 'Upload document'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type BulkDraft = Metadata & { file: File };

export function DocumentBulkUploadDialog({
    ownerName,
    ownerType,
    documentTypes,
    open,
    onOpenChange,
    routes,
}: {
    ownerName: string;
    ownerType: 'company' | 'branch';
    documentTypes: EntityDocumentType[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    routes: Pick<DocumentWorkspaceRoutes, 'bulkStore'>;
}) {
    const form = useForm<{ documents: BulkDraft[] }>({ documents: [] });

    const updateDraft = <K extends keyof Metadata>(
        index: number,
        key: K,
        value: Metadata[K],
    ) => {
        form.setData(
            'documents',
            form.data.documents.map((draft, draftIndex) =>
                draftIndex === index ? { ...draft, [key]: value } : draft,
            ),
        );
    };

    const addFiles = (files: FileList | null) => {
        if (!files) {
            return;
        }

        const available = Math.max(0, 10 - form.data.documents.length);
        const additions = Array.from(files)
            .slice(0, available)
            .map((file) => ({ ...emptyMetadata(), file }));
        form.setData('documents', [...form.data.documents, ...additions]);
    };

    const submit = () => {
        form.post(routes.bulkStore, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Upload multiple documents</DialogTitle>
                    <DialogDescription>
                        Add up to 10 {ownerType} files for {ownerName} and enter
                        metadata for each. The entire batch is rejected if any
                        item fails.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div>
                        <Input
                            type="file"
                            multiple
                            accept={FILE_ACCEPT}
                            onChange={(event) => {
                                addFiles(event.target.files);
                                event.currentTarget.value = '';
                            }}
                        />
                    </div>
                    {form.data.documents.map((draft, index) => (
                        <div
                            key={index}
                            className="space-y-3 rounded-2xl border p-4"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-semibold">
                                        {draft.file.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatFileSize(draft.file.size)}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        form.setData(
                                            'documents',
                                            form.data.documents.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <TypeSelect
                                        value={draft.document_type_id}
                                        onChange={(value) =>
                                            updateDraft(
                                                index,
                                                'document_type_id',
                                                value,
                                            )
                                        }
                                        documentTypes={documentTypes}
                                    />
                                    <InputError
                                        message={
                                            form.errors[
                                                `documents.${index}.document_type_id` as keyof typeof form.errors
                                            ]
                                        }
                                    />
                                </div>
                                <Input
                                    value={draft.title}
                                    placeholder="Title (defaults to type)"
                                    onChange={(event) =>
                                        updateDraft(
                                            index,
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                />
                                <Input
                                    value={draft.document_number}
                                    placeholder="Document number"
                                    onChange={(event) =>
                                        updateDraft(
                                            index,
                                            'document_number',
                                            event.target.value,
                                        )
                                    }
                                />
                                <Input
                                    type="date"
                                    value={draft.issue_date}
                                    onChange={(event) =>
                                        updateDraft(
                                            index,
                                            'issue_date',
                                            event.target.value,
                                        )
                                    }
                                    max={draft.expiry_date || undefined}
                                />
                                <Input
                                    type="date"
                                    min={draft.issue_date || undefined}
                                    value={draft.expiry_date}
                                    onChange={(event) =>
                                        updateDraft(
                                            index,
                                            'expiry_date',
                                            event.target.value,
                                        )
                                    }
                                />
                                <Input
                                    value={draft.notes}
                                    placeholder="Notes"
                                    onChange={(event) =>
                                        updateDraft(
                                            index,
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    ))}
                    {form.data.documents.length === 0 ? (
                        <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">
                            Choose files to build the upload queue.
                        </div>
                    ) : null}
                </div>
                {Object.keys(form.errors).length > 0 ? (
                    <p className="text-sm text-destructive">
                        Review the highlighted batch data. Every file must be
                        valid.
                    </p>
                ) : null}
                {form.progress ? (
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full bg-primary transition-all"
                            style={{
                                width: `${form.progress.percentage ?? 0}%`,
                            }}
                        />
                    </div>
                ) : null}
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={
                            form.processing || form.data.documents.length === 0
                        }
                        onClick={submit}
                    >
                        <Upload className="mr-2 h-4 w-4" /> Upload batch
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DocumentReplaceDialog({
    document,
    open,
    onOpenChange,
    routes,
}: {
    document: EntityDocument | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    routes: Pick<DocumentWorkspaceRoutes, 'replace'>;
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    const submit = () => {
        if (!document) {
            return;
        }

        form.post(routes.replace(document.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Replace document file</DialogTitle>
                    <DialogDescription>
                        The current file will remain available in version
                        history.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
                <InputError message={form.errors.file} />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={!form.data.file || form.processing}
                    >
                        Replace file
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type Version = {
    id: number;
    version: number;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    replaced_by: string | null;
    created_at: string | null;
    download_url: string;
};

export function DocumentVersionsDialog({
    document,
    canDownload,
    open,
    onOpenChange,
    routes,
}: {
    document: EntityDocument | null;
    canDownload: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    routes: Pick<DocumentWorkspaceRoutes, 'versionsIndex'>;
}) {
    const [versions, setVersions] = useState<Version[]>([]);
    const [loadedDocumentId, setLoadedDocumentId] = useState<number | null>(
        null,
    );

    useEffect(() => {
        if (!open || !document) {
            return;
        }

        fetch(routes.versionsIndex(document.id), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Unable to load version history.');
                }

                return response.json() as Promise<{ versions: Version[] }>;
            })
            .then((data) => {
                setVersions(data.versions);
                setLoadedDocumentId(document.id);
            })
            .catch(() => toast.error('Unable to load version history.'));
    }, [document, open, routes]);

    const loading =
        open && document !== null && loadedDocumentId !== document.id;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Version history</DialogTitle>
                    <DialogDescription>{document?.title}</DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    {loading ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Loading…
                        </p>
                    ) : null}
                    {!loading && versions.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            No previous versions.
                        </p>
                    ) : null}
                    {versions.map((version) => (
                        <div
                            key={version.id}
                            className="flex items-center justify-between gap-3 rounded-xl border p-3"
                        >
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold">
                                    Version {version.version} ·{' '}
                                    {version.original_filename}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {(version.size_bytes / 1024 / 1024).toFixed(
                                        2,
                                    )}{' '}
                                    MB
                                    {version.replaced_by
                                        ? ` · Replaced by ${version.replaced_by}`
                                        : ''}
                                </p>
                            </div>
                            {canDownload ? (
                                <Button asChild size="sm" variant="outline">
                                    <a href={version.download_url}>
                                        <Download className="mr-2 h-4 w-4" />
                                        Download
                                    </a>
                                </Button>
                            ) : null}
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
