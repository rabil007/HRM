import { Form, Head, usePage } from '@inertiajs/react';
import { Download, Eye, FileText, Lock, Upload } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/utils';

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
}: Props) {
    const { errors, flash } = usePage().props as {
        errors?: Record<string, string>;
        flash?: { success?: string };
    };
    const [documentTypeId, setDocumentTypeId] = useState(
        document_types[0]?.id ? String(document_types[0].id) : '',
    );

    const expiryLabel = (() => {
        const date = new Date(expires_at);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return date.toLocaleString();
    })();

    return (
        <div className="min-h-screen bg-zinc-950 text-zinc-100">
            <Head title={`Shared documents — ${employee.name}`} />

            <div className="mx-auto flex min-h-screen max-w-3xl flex-col px-4 py-10 sm:px-6">
                <header className="mb-8 space-y-2 border-b border-zinc-800 pb-6">
                    <p className="text-xs font-medium tracking-[0.18em] text-zinc-500 uppercase">
                        Shared {scope === 'folder' ? 'folder' : 'files'}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {employee.name}
                    </h1>
                    <p className="text-sm text-zinc-400">
                        {[company_name, employee.employee_no]
                            .filter(Boolean)
                            .join(' · ')}
                        {expiryLabel ? ` · Expires ${expiryLabel}` : null}
                    </p>
                </header>

                {flash?.success ? (
                    <div className="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                        {flash.success}
                    </div>
                ) : null}

                {!unlocked && requires_password ? (
                    <div className="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
                        <div className="mb-4 flex items-center gap-2 text-zinc-200">
                            <Lock className="h-4 w-4 text-zinc-400" />
                            <h2 className="font-medium">
                                Password protected
                            </h2>
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
                    <div className="space-y-8">
                        <section className="space-y-3">
                            <h2 className="text-sm font-medium text-zinc-300">
                                Files
                            </h2>
                            {documents.length === 0 ? (
                                <p className="rounded-xl border border-dashed border-zinc-800 px-4 py-8 text-center text-sm text-zinc-500">
                                    No files in this share yet.
                                </p>
                            ) : (
                                <ul className="divide-y divide-zinc-800 overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/50">
                                    {documents.map((document) => (
                                        <li
                                            key={document.id}
                                            className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0 flex items-start gap-3">
                                                <FileText className="mt-0.5 h-4 w-4 shrink-0 text-zinc-500" />
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-zinc-100">
                                                        {document.document_name}
                                                    </p>
                                                    <p className="truncate text-xs text-zinc-500">
                                                        {document.document_type}
                                                        {document.size_bytes !=
                                                        null
                                                            ? ` · ${formatBytes(document.size_bytes)}`
                                                            : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                {document.preview_url ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="rounded-lg border-zinc-700"
                                                        asChild
                                                    >
                                                        <a
                                                            href={
                                                                document.preview_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                            View
                                                        </a>
                                                    </Button>
                                                ) : null}
                                                {can_download &&
                                                document.download_url ? (
                                                    <Button
                                                        size="sm"
                                                        className="rounded-lg"
                                                        asChild
                                                    >
                                                        <a
                                                            href={
                                                                document.download_url
                                                            }
                                                        >
                                                            <Download className="mr-1.5 h-3.5 w-3.5" />
                                                            Download
                                                        </a>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {can_upload && upload_url ? (
                            <section className="rounded-2xl border border-zinc-800 bg-zinc-900/50 p-5">
                                <div className="mb-4 flex items-center gap-2">
                                    <Upload className="h-4 w-4 text-zinc-400" />
                                    <h2 className="text-sm font-medium text-zinc-200">
                                        Upload a file
                                    </h2>
                                </div>
                                <Form
                                    action={upload_url}
                                    method="post"
                                    encType="multipart/form-data"
                                    className="space-y-4"
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="document_type_id">
                                            Document type
                                        </Label>
                                        <select
                                            id="document_type_id"
                                            name="document_type_id"
                                            required
                                            value={documentTypeId}
                                            onChange={(event) =>
                                                setDocumentTypeId(
                                                    event.target.value,
                                                )
                                            }
                                            className="flex h-10 w-full rounded-xl border border-zinc-800 bg-zinc-950 px-3 text-sm text-zinc-100"
                                        >
                                            {document_types.map((type) => (
                                                <option
                                                    key={type.id}
                                                    value={type.id}
                                                >
                                                    {type.title}
                                                </option>
                                            ))}
                                        </select>
                                        {errors?.document_type_id ? (
                                            <p className="text-sm text-red-400">
                                                {errors.document_type_id}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="file">File</Label>
                                        <Input
                                            id="file"
                                            name="file"
                                            type="file"
                                            required
                                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                            className="border-zinc-800 bg-zinc-950 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-1 file:text-xs file:text-zinc-200"
                                        />
                                        {errors?.file ? (
                                            <p className="text-sm text-red-400">
                                                {errors.file}
                                            </p>
                                        ) : null}
                                    </div>
                                    <Button type="submit" className="rounded-xl">
                                        Upload
                                    </Button>
                                </Form>
                            </section>
                        ) : null}
                    </div>
                )}
            </div>
        </div>
    );
}
