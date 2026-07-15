import { Form, Head, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    Download,
    Folder,
    FolderOpen,
    Lock,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { SharedDocumentPreview } from '@/features/shared-folder/document-preview';
import { SharedFolderUploadDialog } from '@/features/shared-folder/upload-dialog';
import { cn, formatBytes } from '@/lib/utils';

type SharedDocument = {
    id: number;
    document_name: string;
    document_type: string;
    mime_type: string | null;
    size_bytes: number | null;
    can_preview: boolean;
    download_url: string | null;
    preview_url: string | null;
};

type DocumentTypeOption = {
    id: number;
    title: string;
};

type Props = {
    token: string;
    scope: 'folder' | 'files';
    employee: { name: string; employee_no: string | null };
    company_name: string;
    expires_at: string;
    requires_password: boolean;
    unlocked: boolean;
    can_download: boolean;
    can_upload: boolean;
    unlock_url: string;
    upload_url: string | null;
    documents: SharedDocument[];
    document_types: DocumentTypeOption[];
};

type FolderGroup = {
    name: string;
    documents: SharedDocument[];
};

const UPLOAD_ERROR_KEYS = [
    'file',
    'document_type_id',
    'document_number',
    'issue_date',
    'expiry_date',
    'notes',
] as const;

function groupDocumentsByType(documents: SharedDocument[]): FolderGroup[] {
    const groups = new Map<string, SharedDocument[]>();

    for (const document of documents) {
        const name = document.document_type?.trim() || 'Uncategorized';
        const existing = groups.get(name) ?? [];
        existing.push(document);
        groups.set(name, existing);
    }

    return Array.from(groups.entries())
        .map(([name, docs]) => ({
            name,
            documents: docs,
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
}

function hasUploadErrors(errors?: Record<string, string>): boolean {
    if (!errors) {
        return false;
    }

    return UPLOAD_ERROR_KEYS.some((key) => Boolean(errors[key]));
}

export default function SharedDocumentsShow({
    employee,
    company_name,
    expires_at,
    requires_password,
    unlocked,
    can_download,
    can_upload,
    unlock_url,
    upload_url,
    documents,
    document_types,
    scope,
}: Props): ReactElement {
    const { errors, flash } = usePage().props as {
        errors?: Record<string, string>;
        flash?: { success?: string };
    };

    const folders = useMemo(
        () => groupDocumentsByType(documents),
        [documents],
    );

    const [collapsedFolders, setCollapsedFolders] = useState<
        Record<string, true>
    >({});
    const [selectedId, setSelectedId] = useState<number | null>(
        () => documents[0]?.id ?? null,
    );
    const [manualUploadOpen, setManualUploadOpen] = useState(false);

    const uploadOpen = manualUploadOpen || hasUploadErrors(errors);

    const effectiveSelectedId =
        selectedId !== null &&
        documents.some((document) => document.id === selectedId)
            ? selectedId
            : (documents[0]?.id ?? null);

    const selected =
        documents.find((document) => document.id === effectiveSelectedId) ??
        null;

    const expiryLabel = (() => {
        const date = new Date(expires_at);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return date.toLocaleString();
    })();

    const toggleFolder = (name: string) => {
        setCollapsedFolders((current) => {
            if (current[name]) {
                const next = { ...current };
                delete next[name];

                return next;
            }

            return { ...current, [name]: true };
        });
    };

    const setUploadOpen = (open: boolean) => {
        setManualUploadOpen(open);
    };

    return (
        <div className="min-h-screen bg-zinc-950 text-zinc-100">
            <Head title={`Shared documents — ${employee.name}`} />

            <div className="mx-auto flex min-h-screen max-w-6xl flex-col px-4 py-6 sm:px-6 lg:py-8">
                <header className="mb-6 flex flex-col gap-4 border-b border-zinc-800 pb-5 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                            <Folder className="h-3.5 w-3.5 shrink-0 text-amber-400/90" />
                            {company_name ? (
                                <>
                                    <span className="truncate">
                                        {company_name}
                                    </span>
                                    <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                                </>
                            ) : null}
                            <span className="truncate font-medium text-zinc-300">
                                {employee.name}
                            </span>
                            {employee.employee_no ? (
                                <>
                                    <span className="text-zinc-600">·</span>
                                    <span className="font-mono text-zinc-500">
                                        {employee.employee_no}
                                    </span>
                                </>
                            ) : null}
                        </div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {scope === 'folder'
                                ? 'Shared folder'
                                : 'Shared files'}
                        </h1>
                        <p className="text-sm text-zinc-400">
                            {documents.length}{' '}
                            {documents.length === 1 ? 'file' : 'files'}
                            {expiryLabel ? ` · Expires ${expiryLabel}` : null}
                        </p>
                    </div>

                    {unlocked && can_upload && upload_url ? (
                        <Button
                            type="button"
                            className="shrink-0 rounded-xl"
                            onClick={() => setUploadOpen(true)}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Upload
                        </Button>
                    ) : null}
                </header>

                {flash?.success ? (
                    <div className="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                        {flash.success}
                    </div>
                ) : null}

                {!unlocked && requires_password ? (
                    <div className="mx-auto w-full max-w-md rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
                        <div className="mb-4 flex items-center gap-2 text-zinc-200">
                            <Lock className="h-4 w-4 text-zinc-400" />
                            <h2 className="font-medium">Password protected</h2>
                        </div>
                        <Form
                            action={unlock_url}
                            method="post"
                            className="space-y-4"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    autoFocus
                                    className="border-zinc-800 bg-zinc-950"
                                />
                                {errors?.password ? (
                                    <p className="text-sm text-red-400">
                                        {errors.password}
                                    </p>
                                ) : null}
                            </div>
                            <Button type="submit" className="rounded-xl">
                                Unlock
                            </Button>
                        </Form>
                    </div>
                ) : (
                    <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[minmax(260px,320px)_minmax(0,1fr)]">
                        <aside className="flex min-h-[320px] flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/40">
                            <div className="border-b border-zinc-800 px-4 py-3">
                                <p className="text-xs font-medium tracking-wide text-zinc-500 uppercase">
                                    Folder
                                </p>
                                <p className="mt-1 truncate text-sm font-medium text-zinc-200">
                                    {employee.name}
                                </p>
                            </div>

                            <div className="flex-1 overflow-y-auto p-2">
                                {folders.length === 0 ? (
                                    <p className="px-3 py-8 text-center text-sm text-zinc-500">
                                        No files yet.
                                        {can_upload
                                            ? ' Upload to add documents.'
                                            : ''}
                                    </p>
                                ) : (
                                    <ul className="space-y-1">
                                        {folders.map((folder) => {
                                            const expanded =
                                                !collapsedFolders[folder.name];

                                            return (
                                                <li key={folder.name}>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggleFolder(
                                                                folder.name,
                                                            )
                                                        }
                                                        className="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-left text-sm text-zinc-300 hover:bg-zinc-800/70"
                                                    >
                                                        {expanded ? (
                                                            <FolderOpen className="h-4 w-4 shrink-0 text-amber-400/90" />
                                                        ) : (
                                                            <Folder className="h-4 w-4 shrink-0 text-amber-400/90" />
                                                        )}
                                                        <span className="min-w-0 flex-1 truncate font-medium">
                                                            {folder.name}
                                                        </span>
                                                        <span className="text-[11px] tabular-nums text-zinc-500">
                                                            {
                                                                folder.documents
                                                                    .length
                                                            }
                                                        </span>
                                                    </button>

                                                    {expanded ? (
                                                        <ul className="ml-3 space-y-0.5 border-l border-zinc-800 pl-2">
                                                            {folder.documents.map(
                                                                (document) => {
                                                                    const isSelected =
                                                                        document.id ===
                                                                        effectiveSelectedId;

                                                                    return (
                                                                        <li
                                                                            key={
                                                                                document.id
                                                                            }
                                                                        >
                                                                            <button
                                                                                type="button"
                                                                                onClick={() =>
                                                                                    setSelectedId(
                                                                                        document.id,
                                                                                    )
                                                                                }
                                                                                className={cn(
                                                                                    'flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm transition-colors',
                                                                                    isSelected
                                                                                        ? 'bg-zinc-100 text-zinc-950'
                                                                                        : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200',
                                                                                )}
                                                                            >
                                                                                <DocumentFileIcon
                                                                                    mimeType={
                                                                                        document.mime_type
                                                                                    }
                                                                                    fileName={
                                                                                        document.document_name
                                                                                    }
                                                                                    className="h-4 w-4 shrink-0"
                                                                                />
                                                                                <span className="min-w-0 flex-1 truncate">
                                                                                    {
                                                                                        document.document_name
                                                                                    }
                                                                                </span>
                                                                            </button>
                                                                        </li>
                                                                    );
                                                                },
                                                            )}
                                                        </ul>
                                                    ) : null}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>
                        </aside>

                        <section className="flex min-h-[420px] flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/40">
                            {selected ? (
                                <>
                                    <div className="flex flex-col gap-3 border-b border-zinc-800 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-zinc-100">
                                                {selected.document_name}
                                            </p>
                                            <p className="truncate text-xs text-zinc-500">
                                                {selected.document_type}
                                                {selected.size_bytes != null
                                                    ? ` · ${formatBytes(selected.size_bytes)}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {can_download &&
                                            selected.download_url ? (
                                                <Button
                                                    size="sm"
                                                    className="rounded-lg"
                                                    asChild
                                                >
                                                    <a
                                                        href={
                                                            selected.download_url
                                                        }
                                                    >
                                                        <Download className="mr-1.5 h-3.5 w-3.5" />
                                                        Download
                                                    </a>
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="min-h-0 flex-1 p-3">
                                        <SharedDocumentPreview
                                            title={selected.document_name}
                                            mimeType={selected.mime_type}
                                            previewUrl={selected.preview_url}
                                            canPreview={selected.can_preview}
                                            allowDownload={can_download}
                                            className="h-[min(70vh,640px)]"
                                        />
                                    </div>
                                </>
                            ) : (
                                <div className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-sm text-zinc-500">
                                    <FolderOpen className="h-10 w-10 text-zinc-700" />
                                    <p>Select a file to preview it here.</p>
                                    {can_upload && upload_url ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="mt-2 rounded-xl border-zinc-700"
                                            onClick={() => setUploadOpen(true)}
                                        >
                                            <Upload className="mr-2 h-4 w-4" />
                                            Upload a file
                                        </Button>
                                    ) : null}
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>

            {can_upload && upload_url ? (
                <SharedFolderUploadDialog
                    open={uploadOpen}
                    onOpenChange={setUploadOpen}
                    uploadUrl={upload_url}
                    documentTypes={document_types}
                />
            ) : null}
        </div>
    );
}
