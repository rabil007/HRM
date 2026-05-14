import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { DOCUMENT_STATUS_VARIANTS, documentStatusLabel } from '@/features/organization/employee-documents/status';

type DocumentRow = {
    id: number;
    employee_id: number;
    employee_no: string;
    employee_name: string;
    document_type: string | null;
    document_type_label: string | null;
    title: string | null;
    file_url: string;
    original_filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    current_version: number | null;
    can_preview: boolean;
    issue_date: string | null;
    expiry_date: string | null;
    document_number: string | null;
    status: string | null;
    created_at: string;
};

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Props = {
    documents: DocumentRow[];
    pagination: Pagination;
    counts: Record<string, number>;
    active_status: string | null;
    search: string;
    filters: {
        document_type: string;
        branch_id: string;
        department_id: string;
        expiry_from: string;
        expiry_to: string;
        uploaded_from: string;
        uploaded_to: string;
    };
    filter_options: {
        document_types: { id: number; title: string; slug: string }[];
        branches: { id: number; name: string }[];
        departments: { id: number; name: string }[];
    };
};

const FILTER_TABS = [
    { key: null, label: 'All' },
    { key: 'expired', label: 'Expired' },
    { key: 'expiring_soon', label: 'Expiring Soon' },
    { key: 'valid', label: 'Valid' },
];

function navigate(params: Record<string, string | number | null>) {
    const clean: Record<string, string> = {};
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== '' && v !== undefined) {
            clean[k] = String(v);
        }
    });
    router.get('/organization/documents', clean, { preserveScroll: true });
}

export default function Documents({ documents, pagination, counts, active_status, search, filters, filter_options }: Props) {
    const [searchInput, setSearchInput] = useState(search);
    const [previewDoc, setPreviewDoc] = useState<DocumentRow | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const total = Object.values(counts).reduce((a, b) => a + b, 0);

    const queryState = useMemo(() => ({
        status: active_status,
        search,
        ...filters,
    }), [active_status, filters, search]);

    useEffect(() => {
        if (searchInput === search) {
            return;
        }

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            navigate({ ...queryState, search: searchInput, page: null });
        }, 400);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, [queryState, search, searchInput]);

    return (
        <Main>
            <Head title="Documents" />

            <PageHeader
                title="Documents"
                description="All employee documents across your organisation."
            />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-2">
                {[
                    { label: 'Total', value: total, className: 'text-foreground' },
                    { label: 'Valid', value: counts.valid ?? 0, className: 'text-emerald-500' },
                    { label: 'Expiring Soon', value: counts.expiring_soon ?? 0, className: 'text-amber-500' },
                    { label: 'Expired', value: counts.expired ?? 0, className: 'text-red-500' },
                ].map((s) => (
                    <Card key={s.label} className="glass-card">
                        <CardContent className="p-4">
                            <div className={`text-2xl font-bold tabular-nums ${s.className}`}>{s.value}</div>
                            <div className="text-xs text-muted-foreground mt-0.5">{s.label}</div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <SearchBar
                placeholder="Search employee, type, number…"
                value={searchInput}
                onChange={setSearchInput}
                right={
                    <div className="flex gap-1 bg-muted/40 rounded-lg p-1 border border-border">
                        {FILTER_TABS.map((t) => (
                            <button
                                key={String(t.key)}
                                type="button"
                                onClick={() => navigate({ ...queryState, status: t.key, page: null })}
                                className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors whitespace-nowrap ${
                                    active_status === t.key
                                        ? 'bg-background text-foreground shadow-sm border border-border'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {t.label}
                                {t.key && counts[t.key] ? (
                                    <span className="ml-1.5 tabular-nums opacity-60">{counts[t.key]}</span>
                                ) : null}
                            </button>
                        ))}
                    </div>
                }
            />

            <Card className="glass-card">
                <CardContent className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-8">
                    <select
                        value={filters.document_type}
                        onChange={(e) => navigate({ ...queryState, document_type: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">All document types</option>
                        {filter_options.document_types.map((type) => (
                            <option key={type.id} value={type.slug}>{type.title}</option>
                        ))}
                    </select>
                    <select
                        value={filters.branch_id}
                        onChange={(e) => navigate({ ...queryState, branch_id: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">All branches</option>
                        {filter_options.branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>{branch.name}</option>
                        ))}
                    </select>
                    <select
                        value={filters.department_id}
                        onChange={(e) => navigate({ ...queryState, department_id: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">All departments</option>
                        {filter_options.departments.map((department) => (
                            <option key={department.id} value={department.id}>{department.name}</option>
                        ))}
                    </select>
                    <input
                        type="date"
                        value={filters.expiry_from}
                        onChange={(e) => navigate({ ...queryState, expiry_from: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                        aria-label="Expiry from"
                    />
                    <input
                        type="date"
                        value={filters.expiry_to}
                        onChange={(e) => navigate({ ...queryState, expiry_to: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                        aria-label="Expiry to"
                    />
                    <input
                        type="date"
                        value={filters.uploaded_from}
                        onChange={(e) => navigate({ ...queryState, uploaded_from: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                        aria-label="Uploaded from"
                    />
                    <input
                        type="date"
                        value={filters.uploaded_to}
                        onChange={(e) => navigate({ ...queryState, uploaded_to: e.target.value, page: null })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                        aria-label="Uploaded to"
                    />
                    <button
                        type="button"
                        onClick={() => navigate({ status: active_status, search, page: null })}
                        className="h-9 rounded-md border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        Clear filters
                    </button>
                </CardContent>
            </Card>

            <Card className="glass-card w-full overflow-hidden">
                <CardContent className="w-full p-0 min-h-[360px]">
                    <Table className="min-w-[900px]">
                        <TableHeader>
                            <TableRow className="border-border/60">
                                <TableHead className="pl-4">Employee</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead>Number</TableHead>
                                <TableHead>Issue Date</TableHead>
                                <TableHead>Expiry Date</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="pr-4 text-right">File</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {documents.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="py-16 text-center">
                                        <div className="flex flex-col items-center gap-3 text-muted-foreground">
                                            <FileText className="h-8 w-8 opacity-30" />
                                            <p className="text-sm">No documents found.</p>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                documents.map((doc) => (
                                    <TableRow key={doc.id} className="border-border/40 hover:bg-accent/40">
                                        <TableCell className="pl-4">
                                            <Link
                                                href={`/organization/employees/${doc.employee_id}#documents`}
                                                className="font-semibold text-foreground hover:text-primary transition-colors"
                                            >
                                                {doc.employee_name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground/70">{doc.employee_no}</div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground/80 text-xs">{doc.document_type_label ?? doc.document_type ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{doc.title ?? '—'}</TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground/80">{doc.document_number ?? '—'}</TableCell>
                                        <TableCell className="text-xs text-muted-foreground/80">{doc.issue_date ?? '—'}</TableCell>
                                        <TableCell className={`text-xs font-medium ${doc.status === 'expired' ? 'text-red-400' : doc.status === 'expiring_soon' ? 'text-amber-400' : 'text-muted-foreground/80'}`}>
                                            {doc.expiry_date ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={DOCUMENT_STATUS_VARIANTS[doc.status ?? ''] ?? 'outline'}>
                                                {documentStatusLabel(doc.status)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <div className="flex justify-end gap-3">
                                                {doc.can_preview ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => setPreviewDoc(doc)}
                                                        className="text-xs font-medium text-primary hover:underline"
                                                    >
                                                        Preview
                                                    </button>
                                                ) : null}
                                                <a
                                                    href={doc.file_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-xs font-medium text-muted-foreground hover:text-primary hover:underline"
                                                >
                                                    View
                                                </a>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>

                        {pagination.last_page > 1 && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={8}>
                                        <div className="flex items-center justify-between px-2 py-1">
                                            <p className="text-xs text-muted-foreground">
                                                Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total} documents
                                            </p>
                                            <div className="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    disabled={pagination.current_page === 1}
                                                    onClick={() => navigate({ ...queryState, page: pagination.current_page - 1 })}
                                                    className="h-7 w-7 flex items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                                >
                                                    <ChevronLeft className="h-3.5 w-3.5" />
                                                </button>

                                                {Array.from({ length: pagination.last_page }, (_, i) => i + 1)
                                                    .filter(p => p === 1 || p === pagination.last_page || Math.abs(p - pagination.current_page) <= 2)
                                                    .reduce<(number | '...')[]>((acc, p, idx, arr) => {
                                                        if (idx > 0 && (p as number) - (arr[idx - 1] as number) > 1) {
acc.push('...');
}

                                                        acc.push(p);

                                                        return acc;
                                                    }, [])
                                                    .map((p, i) =>
                                                        p === '...' ? (
                                                            <span key={`ellipsis-${i}`} className="px-1 text-xs text-muted-foreground">…</span>
                                                        ) : (
                                                            <button
                                                                key={p}
                                                                type="button"
                                                                onClick={() => navigate({ ...queryState, page: p as number })}
                                                                className={`h-7 min-w-7 px-2 rounded-md border text-xs font-medium transition-colors ${
                                                                    p === pagination.current_page
                                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                                        : 'border-border text-muted-foreground hover:text-foreground hover:bg-muted'
                                                                }`}
                                                            >
                                                                {p}
                                                            </button>
                                                        )
                                                    )}

                                                <button
                                                    type="button"
                                                    disabled={pagination.current_page === pagination.last_page}
                                                    onClick={() => navigate({ ...queryState, page: pagination.current_page + 1 })}
                                                    className="h-7 w-7 flex items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                                >
                                                    <ChevronRight className="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </CardContent>
            </Card>

            <DocumentPreviewDialog document={previewDoc} onOpenChange={(open) => !open && setPreviewDoc(null)} />
        </Main>
    );
}
