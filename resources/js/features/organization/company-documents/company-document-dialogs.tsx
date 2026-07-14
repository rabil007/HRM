import { useForm } from '@inertiajs/react';
import { Download, Trash2, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { index as versionsIndex } from '@/actions/App/Http/Controllers/Organization/CompanyDocumentVersionController';
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
import { toast } from '@/lib/toast';
import {
    bulkStore,
    replace,
    store,
    update,
} from '@/routes/organization/companies/documents';
import type {
    CompanyDocument,
    CompanyDocumentCompany,
    CompanyDocumentType,
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

const emptyMetadata = (): Metadata => ({
    document_type_id: '',
    title: '',
    document_number: '',
    issue_date: '',
    expiry_date: '',
    notes: '',
});

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-xs text-destructive">{message}</p>
    ) : null;
}

function TypeSelect({
    value,
    onChange,
    documentTypes,
}: {
    value: number | '';
    onChange: (value: number | '') => void;
    documentTypes: CompanyDocumentType[];
}) {
    return (
        <select
            value={value}
            onChange={(event) =>
                onChange(event.target.value ? Number(event.target.value) : '')
            }
            className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm"
            required
        >
            <option value="">Select document type</option>
            {documentTypes.map((type) => (
                <option key={type.id} value={type.id}>
                    {type.title}
                </option>
            ))}
        </select>
    );
}

export function CompanyDocumentFormDialog({
    company,
    documentTypes,
    document,
    open,
    onOpenChange,
}: {
    company: CompanyDocumentCompany;
    documentTypes: CompanyDocumentType[];
    document: CompanyDocument | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
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
            form.put(update.url([company.id, document.id]), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });

            return;
        }

        form.post(store.url(company.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {document
                            ? 'Edit document metadata'
                            : 'Upload document'}
                    </DialogTitle>
                    <DialogDescription>
                        PDF, JPG, JPEG, or PNG. Maximum file size is 20 MB.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    {!document ? (
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="company-document-file">File</Label>
                            <Input
                                id="company-document-file"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                onChange={(event) =>
                                    form.setData(
                                        'file',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                                required
                            />
                            <FieldError message={form.errors.file} />
                        </div>
                    ) : null}
                    <div className="space-y-2">
                        <Label>Document type</Label>
                        <TypeSelect
                            value={form.data.document_type_id}
                            onChange={(value) =>
                                form.setData('document_type_id', value)
                            }
                            documentTypes={documentTypes}
                        />
                        <FieldError message={form.errors.document_type_id} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="company-document-title">Title</Label>
                        <Input
                            id="company-document-title"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            placeholder="Defaults to document type"
                        />
                        <FieldError message={form.errors.title} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="company-document-number">
                            Document number
                        </Label>
                        <Input
                            id="company-document-number"
                            value={form.data.document_number}
                            onChange={(event) =>
                                form.setData(
                                    'document_number',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="company-document-issue-date">
                            Issue date
                        </Label>
                        <Input
                            id="company-document-issue-date"
                            type="date"
                            value={form.data.issue_date}
                            onChange={(event) =>
                                form.setData('issue_date', event.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="company-document-expiry-date">
                            Expiry date
                        </Label>
                        <Input
                            id="company-document-expiry-date"
                            type="date"
                            value={form.data.expiry_date}
                            min={form.data.issue_date || undefined}
                            onChange={(event) =>
                                form.setData('expiry_date', event.target.value)
                            }
                        />
                        <FieldError message={form.errors.expiry_date} />
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="company-document-notes">Notes</Label>
                        <Textarea
                            id="company-document-notes"
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            rows={3}
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
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {document ? 'Save changes' : 'Upload document'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type BulkDraft = Metadata & { file: File };

export function CompanyDocumentBulkUploadDialog({
    company,
    documentTypes,
    open,
    onOpenChange,
}: {
    company: CompanyDocumentCompany;
    documentTypes: CompanyDocumentType[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
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
        form.post(bulkStore.url(company.id), {
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
                        Add up to 10 files and enter metadata for each. The
                        entire batch is rejected if any item fails.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex items-center justify-between gap-3">
                    <Input
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        onChange={(event) => {
                            addFiles(event.target.files);
                            event.target.value = '';
                        }}
                        disabled={form.data.documents.length >= 10}
                    />
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {form.data.documents.length}/10
                    </span>
                </div>
                <div className="space-y-4">
                    {form.data.documents.map((draft, index) => (
                        <div
                            key={`${draft.file.name}-${index}`}
                            className="rounded-xl border p-4"
                        >
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold">
                                        {draft.file.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {(
                                            draft.file.size /
                                            1024 /
                                            1024
                                        ).toFixed(2)}{' '}
                                        MB
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    onClick={() =>
                                        form.setData(
                                            'documents',
                                            form.data.documents.filter(
                                                (_, itemIndex) =>
                                                    itemIndex !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                                <Input
                                    value={draft.title}
                                    placeholder="Title (optional)"
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

export function CompanyDocumentReplaceDialog({
    company,
    document,
    open,
    onOpenChange,
}: {
    company: CompanyDocumentCompany;
    document: CompanyDocument | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    const submit = () => {
        if (!document) {
            return;
        }

        form.post(replace.url([company.id, document.id]), {
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
                <FieldError message={form.errors.file} />
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
    replaced_at: string | null;
    download_url: string;
};

export function CompanyDocumentVersionsDialog({
    company,
    document,
    canDownload,
    open,
    onOpenChange,
}: {
    company: CompanyDocumentCompany;
    document: CompanyDocument | null;
    canDownload: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [versions, setVersions] = useState<Version[]>([]);
    const [loadedDocumentId, setLoadedDocumentId] = useState<number | null>(
        null,
    );

    useEffect(() => {
        if (!open || !document) {
            return;
        }

        fetch(versionsIndex.url([company.id, document.id]), {
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
    }, [company.id, document, open]);

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
