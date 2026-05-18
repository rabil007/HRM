import { router, useForm } from '@inertiajs/react';
import { FileText, UploadCloud, X } from 'lucide-react';
import { useCallback, useMemo, useState  } from 'react';
import type {ReactElement} from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { TabsContent } from '@/components/ui/tabs';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { DOCUMENT_STATUS_CLASSES, documentStatusLabel } from '@/features/organization/employee-documents/status';
import { toast } from '@/lib/toast';
import type { DocumentTypeOption, EmployeeDetails, EmployeeDocumentItem } from '@/pages/organization/employee-page.types';

const DOCUMENTS_RELOAD = {
    preserveScroll: true,
    only: ['documents'],
} as const;

type DocumentVersionItem = EmployeeDocumentItem['versions'][number];

export type EmployeeDocumentsTabProps = {
    employee: Pick<EmployeeDetails, 'id' | 'name'>;
    documents: EmployeeDocumentItem[];
    document_types: DocumentTypeOption[];
    can: {
        documents_upload: boolean;
        documents_delete: boolean;
    };
};

export function EmployeeDocumentsTab({ employee, documents, document_types, can }: EmployeeDocumentsTabProps): ReactElement {
    const [uploadOpen, setUploadOpen] = useState(false);
    const [editDoc, setEditDoc] = useState<EmployeeDocumentItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [previewDoc, setPreviewDoc] = useState<EmployeeDocumentItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<EmployeeDocumentItem | null>(null);
    const [versionDoc, setVersionDoc] = useState<EmployeeDocumentItem | null>(null);
    const [versionHistory, setVersionHistory] = useState<DocumentVersionItem[]>([]);
    const [versionsLoading, setVersionsLoading] = useState(false);
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

    const editForm = useForm({
        title: '',
        document_number: '',
        issue_date: '',
        expiry_date: '',
        notes: '',
    });

    const replaceForm = useForm({
        file: null as File | null,
    });

    const addUploadFiles = useCallback((files: File[]) => {
        const supportedFiles = files.filter((file) => {
            return ['application/pdf', 'image/jpeg', 'image/png'].includes(file.type);
        });

        setBulkFiles((current) => {
            const next = [...current];

            supportedFiles.forEach((file) => {
                const exists = next.some((item) => {
                    return item.name === file.name && item.size === file.size && item.lastModified === file.lastModified;
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
    }, [uploadForm]);

    const removeUploadFile = useCallback((fileIndex: number) => {
        setBulkFiles((current) => {
            const next = current.filter((_, index) => index !== fileIndex);
            uploadForm.setData('file', next[0] ?? null);

            return next;
        });
    }, [uploadForm]);

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
<TabsContent value="documents" className="mt-6">
    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
        <div className="mb-4 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-zinc-200">
                Documents
                <span className="ml-2 text-xs font-normal text-zinc-500">{documents.length} total</span>
            </h3>
            {can.documents_upload && (
                <Button
                    size="sm"
                    className="h-8 gap-1.5 text-xs"
                    onClick={() => {
                        uploadForm.reset();
                        setBulkFiles([]);
                        setUploadOpen(true);
                    }}
                >
                    + Upload Document
                </Button>
            )}
        </div>

        {documents.length === 0 ? (
            <div className="py-10 text-center text-sm text-zinc-500">
                No documents uploaded.
            </div>
        ) : (
            <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-left">
                    <thead>
                        <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                            <th className="py-2 pr-4">Type</th>
                            <th className="py-2 pr-4">Title</th>
                            <th className="py-2 pr-4">Number</th>
                            <th className="py-2 pr-4">Issue</th>
                            <th className="py-2 pr-4">Expiry</th>
                            <th className="py-2 pr-4">Status</th>
                            <th className="py-2 pr-4">Uploaded by</th>
                            <th className="py-2 pr-4">File</th>
                            {(can.documents_upload || can.documents_delete) && (
                                <th className="py-2 pr-4" />
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {documents.map((doc) => {
                            const statusColor = DOCUMENT_STATUS_CLASSES[doc.status ?? ''] ?? 'bg-white/5 text-zinc-400 border-white/10';

                            return (
                                <tr key={doc.id} className="text-sm text-zinc-200">
                                    <td className="py-3 pr-4 text-zinc-400 text-xs">
                                        {doc.document_type_label ?? document_types.find(t => String(t.id) === String(doc.document_type_id ?? doc.document_type))?.title ?? doc.document_type ?? doc.type ?? '—'}
                                        {doc.current_version && doc.current_version > 1 ? (
                                            <span className="ml-1 text-[10px] text-zinc-500">v{doc.current_version}</span>
                                        ) : null}
                                    </td>
                                    <td className="py-3 pr-4 font-medium">{doc.title || '—'}</td>
                                    <td className="py-3 pr-4 text-zinc-400 font-mono text-xs">{doc.document_number || '—'}</td>
                                    <td className="py-3 pr-4 text-zinc-400 text-xs">{doc.issue_date || '—'}</td>
                                    <td className="py-3 pr-4 text-zinc-400 text-xs">{doc.expiry_date || '—'}</td>
                                    <td className="py-3 pr-4">
                                        <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium capitalize ${statusColor}`}>
                                            {documentStatusLabel(doc.status)}
                                        </span>
                                    </td>
                                    <td className="py-3 pr-4 text-zinc-500 text-xs">{doc.uploaded_by || '—'}</td>
                                    <td className="py-3 pr-4">
                                        <div className="flex gap-2">
                                            {doc.can_preview ? (
                                                <button type="button" onClick={() => setPreviewDoc(doc)} className="text-xs font-semibold text-primary hover:underline">
                                                    Preview
                                                </button>
                                            ) : null}
                                            <a href={doc.file_url} target="_blank" rel="noreferrer" className="text-xs font-semibold text-zinc-400 hover:text-primary hover:underline">
                                                View
                                            </a>
                                        </div>
                                    </td>
                                    {(can.documents_upload || can.documents_delete) && (
                                        <td className="py-3 pr-4">
                                            <div className="flex items-center gap-2">
                                                {can.documents_upload && (
                                                    <>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                        onClick={() => {
                                                            setVersionDoc(doc);
                                                            setVersionHistory([]);
                                                            setVersionsLoading(true);

                                                            fetch(
                                                                `/organization/employees/${employee.id}/documents/${doc.id}/versions`,
                                                                {
                                                                    headers: {
                                                                        Accept: 'application/json',
                                                                        'X-Requested-With': 'XMLHttpRequest',
                                                                    },
                                                                },
                                                            )
                                                                .then((response) => response.json())
                                                                .then((data: { versions?: DocumentVersionItem[] }) => {
                                                                    setVersionHistory(data.versions ?? []);
                                                                })
                                                                .catch(() => {
                                                                    setVersionHistory([]);
                                                                })
                                                                .finally(() => {
                                                                    setVersionsLoading(false);
                                                                });
                                                        }}
                                                    >
                                                        Versions
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                        onClick={() => {
                                                            replaceForm.reset();
                                                            setReplaceDoc(doc);
                                                        }}
                                                    >
                                                        Replace
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                        onClick={() => {
                                                            setEditDoc(doc);
                                                            editForm.setData({
                                                                title: doc.title ?? '',
                                                                document_number: doc.document_number ?? '',
                                                                issue_date: doc.issue_date ?? '',
                                                                expiry_date: doc.expiry_date ?? '',
                                                                notes: doc.notes ?? '',
                                                            });
                                                        }}
                                                    >
                                                        Edit
                                                    </button>
                                                    </>
                                                )}
                                                {can.documents_delete && (
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 hover:text-red-400 transition-colors"
                                                        onClick={() => setDeleteDocId(doc.id)}
                                                    >
                                                        Delete
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        )}
    </div>

    <Dialog
        open={uploadOpen}
        onOpenChange={(open) => {
            setUploadOpen(open);

            if (!open) {
                resetUploadDialog();
            }
        }}
    >
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Upload Employee Documents</DialogTitle>
                <p className="text-sm text-muted-foreground">
                    Add one or many files for {employee.name}. Shared details below will be applied to every selected file.
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
                                <Button variant="ghost" size="sm" onClick={() => {
                                    setBulkFiles([]);
                                    uploadForm.setData('file', null);
                                }}>
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
                                bulkFiles.map((file, index) => (
                                    <div key={`${file.name}-${file.size}-${file.lastModified}`} className="flex items-center justify-between gap-3 rounded-xl border border-border bg-background px-3 py-2">
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
                                            onClick={() => removeUploadFile(index)}
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
                        <Label className="text-xs">Document Type <span className="text-destructive">*</span></Label>
                        <select
                            value={uploadForm.data.document_type_id}
                            onChange={(event) => uploadForm.setData('document_type_id', event.target.value)}
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                        >
                            <option value="">Select type…</option>
                            {document_types.map((type) => (
                                <option key={type.id} value={String(type.id)}>{type.title}</option>
                            ))}
                        </select>
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

            <DialogFooter className="items-center sm:justify-between">
                <div className="text-xs text-muted-foreground">
                    {bulkFiles.length > 1 ? 'Bulk upload will create one document record per file.' : 'Select at least one file to upload.'}
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => setUploadOpen(false)}>Cancel</Button>
                    <Button
                        size="sm"
                        disabled={uploadForm.processing || bulkFiles.length === 0 || !uploadForm.data.document_type_id}
                        onClick={() => {
                            if (bulkFiles.length > 1) {
                                router.post(
                                    `/organization/employees/${employee.id}/documents/bulk`,
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
                                            setUploadOpen(false);
                                            resetUploadDialog();
                                            toast.success('Documents uploaded.');
                                        },
                                    },
                                );

                                return;
                            }

                            uploadForm.post(`/organization/employees/${employee.id}/documents`, {
                                forceFormData: true,
                                onSuccess: () => {
                                    setUploadOpen(false);
                                    resetUploadDialog();
                                    toast.success('Document uploaded.');
                                },
                            });
                        }}
                    >
                        {uploadForm.processing ? 'Uploading…' : `Upload ${bulkFiles.length || ''}`.trim()}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    {/* Edit Dialog */}
    <Dialog open={!!editDoc} onOpenChange={open => {
 if (!open) {
setEditDoc(null);
} 
}}>
        <DialogContent className="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Edit Document</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 py-2">
                <div className="space-y-1.5">
                    <Label className="text-xs">Title</Label>
                    <Input className="h-9 text-sm" value={editForm.data.title} onChange={e => editForm.setData('title', e.target.value)} />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <Label className="text-xs">Document Number</Label>
                        <Input className="h-9 text-sm" value={editForm.data.document_number} onChange={e => editForm.setData('document_number', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs">Issue Date</Label>
                        <Input type="date" className="h-9 text-sm" value={editForm.data.issue_date} onChange={e => editForm.setData('issue_date', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs">Expiry Date</Label>
                        <Input type="date" className="h-9 text-sm" value={editForm.data.expiry_date} onChange={e => editForm.setData('expiry_date', e.target.value)} />
                    </div>
                </div>
                <div className="space-y-1.5">
                    <Label className="text-xs">Notes</Label>
                    <textarea
                        rows={2}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary resize-none"
                        value={editForm.data.notes}
                        onChange={e => editForm.setData('notes', e.target.value)}
                    />
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" size="sm" onClick={() => setEditDoc(null)}>Cancel</Button>
                <Button
                    size="sm"
                    disabled={editForm.processing}
                    onClick={() => {
                        if (!editDoc) {
return;
}

                        editForm.put(`/organization/employees/${employee.id}/documents/${editDoc.id}`, {
                            onSuccess: () => {
                                setEditDoc(null);
                                toast.success('Document updated.');
                            },
                        });
                    }}
                >
                    {editForm.processing ? 'Saving…' : 'Save Changes'}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog open={!!replaceDoc} onOpenChange={open => {
 if (!open) {
setReplaceDoc(null);
} 
}}>
        <DialogContent className="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Replace Document File</DialogTitle>
            </DialogHeader>
            <div className="space-y-3 py-2">
                <p className="text-sm text-muted-foreground">
                    The current file will be kept in version history.
                </p>
                <input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={e => replaceForm.setData('file', e.target.files?.[0] ?? null)}
                    className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                />
                {replaceForm.errors.file && <p className="text-xs text-destructive">{replaceForm.errors.file}</p>}
            </div>
            <DialogFooter>
                <Button variant="outline" size="sm" onClick={() => setReplaceDoc(null)}>Cancel</Button>
                <Button
                    size="sm"
                    disabled={replaceForm.processing}
                    onClick={() => {
                        if (!replaceDoc) {
return;
}

                        replaceForm.post(`/organization/employees/${employee.id}/documents/${replaceDoc.id}/replace`, {
                            forceFormData: true,
                            onSuccess: () => {
                                setReplaceDoc(null);
                                replaceForm.reset();
                                toast.success('Document file replaced.');
                            },
                        });
                    }}
                >
                    {replaceForm.processing ? 'Replacing…' : 'Replace'}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog open={!!versionDoc} onOpenChange={open => {
 if (!open) {
setVersionDoc(null);
setVersionHistory([]);
} 
}}>
        <DialogContent className="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Version History</DialogTitle>
            </DialogHeader>
            <div className="space-y-3 py-2">
                <div className="rounded-lg border border-border p-3">
                    <div className="text-sm font-medium">Current version v{versionDoc?.current_version ?? 1}</div>
                    <div className="text-xs text-muted-foreground">{versionDoc?.original_filename ?? 'Current file'}</div>
                </div>
                {versionsLoading ? (
                    <p className="text-sm text-muted-foreground">Loading version history…</p>
                ) : versionHistory.length ? (
                    <div className="space-y-2">
                        {versionHistory.map((version) => (
                            <div key={version.id} className="flex items-center justify-between rounded-lg border border-border p-3">
                                <div>
                                    <div className="text-sm font-medium">Version v{version.version}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {[version.original_filename, version.replaced_by ? `replaced by ${version.replaced_by}` : null].filter(Boolean).join(' · ')}
                                    </div>
                                </div>
                                <a href={version.file_url} target="_blank" rel="noreferrer" className="text-xs font-medium text-primary hover:underline">
                                    View
                                </a>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">No previous versions yet.</p>
                )}
            </div>
        </DialogContent>
    </Dialog>

    <DocumentPreviewDialog document={previewDoc} onOpenChange={(open) => !open && setPreviewDoc(null)} />

    {/* Delete Confirmation */}
    <AlertDialog open={!!deleteDocId} onOpenChange={open => {
 if (!open) {
setDeleteDocId(null);
} 
}}>
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete document?</AlertDialogTitle>
                <AlertDialogDescription>
                    The file and all metadata will be permanently removed. This cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    onClick={() => {
                        if (!deleteDocId) {
return;
}

                        router.delete(`/organization/employees/${employee.id}/documents/${deleteDocId}`, {
                            ...DOCUMENTS_RELOAD,
                            onSuccess: () => {
                                setDeleteDocId(null);
                                toast.success('Document deleted.');
                            },
                        });
                    }}
                >
                    Delete
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</TabsContent>

    );
}
