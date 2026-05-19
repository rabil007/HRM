import { Head, Link, router } from '@inertiajs/react';
import { Download, FileText, Trash2 } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import EmployeeDocumentBulkDelete from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentBulkDeleteController';
import EmployeeDocumentDownload from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentDownloadController';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { TableRowActions } from '@/components/table-row-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { DocumentCard } from '@/features/organization/employee-documents/document-card';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { DocumentVersionsSheet } from '@/features/organization/employee-documents/document-versions-sheet';
import { DOCUMENT_STATUS_CLASSES, DOCUMENT_STATUS_VARIANTS, documentStatusLabel } from '@/features/organization/employee-documents/status';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import type { PaginationMeta } from '@/types/pagination';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatBytes } from '@/lib/utils';

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

type Props = {
    documents: DocumentRow[];
    pagination: PaginationMeta;
    counts: Record<string, number>;
    search: string;
    filters: {
        document_type: string;
        expiry_within: string;
    };
    filter_options: {
        document_types: { id: number; title: string }[];
    };
};

export default function Documents({
    documents,
    pagination,
    counts,
    search,
    filters,
    filter_options,
}: Props) {
    const [previewDoc, setPreviewDoc] = useState<DocumentRow | null>(null);
    const [versionsDoc, setVersionsDoc] = useState<DocumentRow | null>(null);
    const [view, setView] = useViewPreference('documents:view', 'list');
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const total = Object.values(counts).reduce((a, b) => a + b, 0);

    const docIds = useMemo(() => (documents ?? []).map((d) => d.id), [documents]);
    const allSelected = docIds.length > 0 && docIds.every((id) => selectedIds.has(id));
    const someSelected = !allSelected && docIds.some((id) => selectedIds.has(id));

    const toggleAll = useCallback(() => {
        setSelectedIds((prev) => {
            if (allSelected) {
                const next = new Set(prev);
                docIds.forEach((id) => next.delete(id));

                return next;
            }

            return new Set([...prev, ...docIds]);
        });
    }, [allSelected, docIds]);

    const toggleOne = useCallback((id: number) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
 next.delete(id); 
} else {
 next.add(id); 
}

            return next;
        });
    }, []);

    const handleBulkDelete = useCallback(() => {
        if (selectedIds.size === 0 || bulkDeleting) {
return;
}

        if (!window.confirm(`Delete ${selectedIds.size} document(s)? This cannot be undone.`)) {
return;
}

        setBulkDeleting(true);
        router.delete(EmployeeDocumentBulkDelete.url(), {
            data: { ids: Array.from(selectedIds) },
            preserveScroll: true,
            onSuccess: () => setSelectedIds(new Set()),
            onFinish: () => setBulkDeleting(false),
        });
    }, [bulkDeleting, selectedIds]);

    const list = useServerPaginationFilters({
        url: '/organization/documents',
        search,
        filters: {
            document_type: filters.document_type,
            expiry_within: filters.expiry_within,
        },
        pagination,
    });

    const employeeGroups = useMemo(() => {
        const map = new Map<number, { employee_id: number; employee_name: string; employee_no: string; docs: DocumentRow[] }>();

        for (const doc of (documents ?? [])) {
            const existing = map.get(doc.employee_id);

            if (existing) {
                existing.docs.push(doc);
            } else {
                map.set(doc.employee_id, {
                    employee_id: doc.employee_id,
                    employee_name: doc.employee_name,
                    employee_no: doc.employee_no,
                    docs: [doc],
                });
            }
        }

        return Array.from(map.values());
    }, [documents]);

    const expiryChips = [
        { label: 'Expiring in 30 days', value: '30' },
        { label: '60 days', value: '60' },
        { label: '90 days', value: '90' },
    ];

    return (
        <Main>
            <Head title="Documents" />

            <PageHeader
                title="Documents"
                description="All employee documents across your organisation."
            />

            <div className="grid grid-cols-2 gap-4 mb-2 sm:grid-cols-4">
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

            <div className="mb-6 rounded-xl border border-border/50 bg-card/30 p-3 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:gap-4">
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="text-xs font-medium text-muted-foreground">Search</span>
                        <SearchBar
                            className="mb-0 flex-1 [&>div]:w-full"
                            inputClassName="h-10 rounded-lg py-2 text-sm"
                            placeholder="Search by employee name or number…"
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                        />
                    </div>
                    <label className="flex w-full shrink-0 flex-col gap-1 sm:w-56">
                        <span className="text-xs font-medium text-muted-foreground">Document type</span>
                        <select
                            value={filters.document_type}
                            onChange={(e) =>
                                list.applyFilters({ document_type: e.target.value })
                            }
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/30"
                        >
                            <option value="">All document types</option>
                            {(filter_options?.document_types ?? []).map((type) => (
                                <option key={type.id} value={String(type.id)}>
                                    {type.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex shrink-0 flex-col gap-1">
                        <span className="text-xs font-medium text-muted-foreground">View</span>
                        <ViewToggle value={view} onChange={setView} showEmployeeView employeeLabel="By employee" />
                    </div>
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <span className="text-xs font-medium text-muted-foreground">Expiry:</span>
                {expiryChips.map((chip) => (
                    <button
                        key={chip.value}
                        type="button"
                        onClick={() => list.visit({
                            expiry_within: filters.expiry_within === chip.value ? '' : chip.value,
                            page: null,
                        })}
                        className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            filters.expiry_within === chip.value
                                ? 'border-amber-500/60 bg-amber-500/15 text-amber-400'
                                : 'border-border/60 bg-muted/30 text-muted-foreground hover:border-amber-500/40 hover:text-amber-400'
                        }`}
                    >
                        {chip.label}
                    </button>
                ))}
                {filters.expiry_within ? (
                    <button
                        type="button"
                        onClick={() => list.visit({ expiry_within: '', page: null })}
                        className="ml-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Clear
                    </button>
                ) : null}
            </div>

            {view === 'employee' ? (
                <>
                    {employeeGroups.length === 0 ? (
                        <div className="flex min-h-[360px] flex-col items-center justify-center gap-3 text-muted-foreground">
                            <FileText className="h-10 w-10 opacity-20" />
                            <p className="text-sm">No documents found.</p>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {employeeGroups.map((group) => {
                                const expired = group.docs.filter((d) => d.status === 'expired').length;
                                const expiring = group.docs.filter((d) => d.status === 'expiring_soon').length;

                                return (
                                    <div key={group.employee_id}>
                                        <div className="mb-3 flex items-center gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary uppercase">
                                                {(group.employee_name ?? '?').charAt(0)}
                                            </div>
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/organization/employees/${group.employee_id}#documents`}
                                                    className="font-semibold text-foreground hover:text-primary transition-colors"
                                                >
                                                    {group.employee_name}
                                                </Link>
                                                <p className="text-xs text-muted-foreground/60">{group.employee_no}</p>
                                            </div>
                                            <div className="ml-auto flex items-center gap-3 text-xs text-muted-foreground/70">
                                                <span className="tabular-nums">{group.docs.length} doc{group.docs.length !== 1 ? 's' : ''}</span>
                                                {expired > 0 ? <span className="text-red-400">{expired} expired</span> : null}
                                                {expiring > 0 ? <span className="text-amber-400">{expiring} expiring</span> : null}
                                                <a
                                                    href={EmployeeDocumentDownload.url(group.employee_id)}
                                                    className="flex items-center gap-1 rounded-lg border border-border/50 bg-muted/30 px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary"
                                                    title="Download all as ZIP"
                                                >
                                                    <Download className="h-3 w-3" />
                                                    ZIP
                                                </a>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 pl-12">
                                            {group.docs.map((doc) => (
                                                <DocumentCard key={doc.id} doc={doc} onPreview={setPreviewDoc} onViewHistory={setVersionsDoc} />
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    <Pagination {...list.paginationProps} label="documents" />
                </>
            ) : view === 'grid' ? (
                <>
                    {(documents ?? []).length === 0 ? (
                        <div className="flex min-h-[360px] flex-col items-center justify-center gap-3 text-muted-foreground">
                            <FileText className="h-10 w-10 opacity-20" />
                            <p className="text-sm">No documents found.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                            {(documents ?? []).map((doc) => (
                                <DocumentCard key={doc.id} doc={doc} onPreview={setPreviewDoc} onViewHistory={setVersionsDoc} />
                            ))}
                        </div>
                    )}
                    <Pagination {...list.paginationProps} label="documents" />
                </>
            ) : (
                <>
                {selectedIds.size > 0 && (
                    <div className="mb-3 flex items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-2.5">
                        <span className="text-sm font-medium text-foreground">
                            {selectedIds.size} selected
                        </span>
                        <div className="ml-auto flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 gap-1.5 rounded-lg text-xs text-muted-foreground hover:text-foreground"
                                onClick={() => setSelectedIds(new Set())}
                            >
                                Clear
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                className="h-8 gap-1.5 rounded-lg text-xs"
                                onClick={handleBulkDelete}
                                disabled={bulkDeleting}
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                                {bulkDeleting ? 'Deleting…' : `Delete ${selectedIds.size}`}
                            </Button>
                        </div>
                    </div>
                )}
                <OrganizationDataTable minWidth="min-w-[900px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="w-10 pl-5">
                                <Checkbox
                                    checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                                    onCheckedChange={toggleAll}
                                    aria-label="Select all"
                                />
                            </DataTableHead>
                            <DataTableHead>Employee</DataTableHead>
                            <DataTableHead>Type</DataTableHead>
                            <DataTableHead>Title</DataTableHead>
                            <DataTableHead>Number</DataTableHead>
                            <DataTableHead>Issue Date</DataTableHead>
                            <DataTableHead>Expiry Date</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Size</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                            <TableBody>
                                {(documents ?? []).length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="py-16 text-center">
                                            <div className="flex flex-col items-center gap-3 text-muted-foreground">
                                                <FileText className="h-8 w-8 opacity-30" />
                                                <p className="text-sm">No documents found.</p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    (documents ?? []).map((doc) => (
                                        <TableRow
                                            key={doc.id}
                                            className={`${dataTableBodyRowClass()} ${selectedIds.has(doc.id) ? 'bg-primary/5' : ''}`}
                                            onClick={() => window.open(`/organization/employees/${doc.employee_id}#documents`, '_self')}
                                        >
                                            <TableCell className={`w-10 ${dataTableCellClass()}`} onClick={(e) => e.stopPropagation()}>
                                                <Checkbox
                                                    checked={selectedIds.has(doc.id)}
                                                    onCheckedChange={() => toggleOne(doc.id)}
                                                    aria-label={`Select ${doc.employee_name}`}
                                                />
                                            </TableCell>
                                            <TableCell className={dataTableCellPrimaryClass()}>
                                                <div>{doc.employee_name}</div>
                                                <div className="text-xs text-muted-foreground/70">{doc.employee_no}</div>
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                {doc.document_type_label ?? doc.document_type ?? '—'}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>{doc.title ?? '—'}</TableCell>
                                            <TableCell className={`font-mono text-xs ${dataTableCellClass()}`}>{doc.document_number ?? '—'}</TableCell>
                                            <TableCell className={dataTableCellClass()}>{doc.issue_date ?? '—'}</TableCell>
                                            <TableCell
                                                className={`${dataTableCellClass()} font-medium ${doc.status === 'expired' ? 'text-red-400' : doc.status === 'expiring_soon' ? 'text-amber-400' : ''}`}
                                            >
                                                {doc.expiry_date ?? '—'}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass()}>
                                                <Badge variant={DOCUMENT_STATUS_VARIANTS[doc.status ?? ''] ?? 'outline'} className={`text-[10px] uppercase font-bold tracking-wider border ${DOCUMENT_STATUS_CLASSES[doc.status ?? ''] ?? ''}`}>
                                                    {documentStatusLabel(doc.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className={`${dataTableCellClass()} text-right font-mono text-xs tabular-nums`}>
                                                {formatBytes(doc.size_bytes)}
                                            </TableCell>
                                            <TableCell className={dataTableActionsCellClass()}>
                                                <TableRowActions
                                                    actions={[
                                                        {
                                                            label: 'Preview',
                                                            variant: 'primary',
                                                            onClick: () => setPreviewDoc(doc),
                                                            hidden: !doc.can_preview,
                                                        },
                                                        {
                                                            label: 'View',
                                                            href: doc.file_url,
                                                            target: '_blank',
                                                            rel: 'noreferrer',
                                                        },
                                                        {
                                                            label: 'Versions',
                                                            onClick: () => setVersionsDoc(doc),
                                                            hidden: (doc.current_version ?? 1) <= 1,
                                                        },
                                                    ]}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                </OrganizationDataTable>
                <Pagination {...list.paginationProps} label="documents" />
                </>
            )}

            <DocumentPreviewDialog document={previewDoc} onOpenChange={(open) => !open && setPreviewDoc(null)} />
            <DocumentVersionsSheet
                open={!!versionsDoc}
                onOpenChange={(open) => !open && setVersionsDoc(null)}
                employeeId={versionsDoc?.employee_id ?? null}
                documentId={versionsDoc?.id ?? null}
                documentTitle={versionsDoc?.title ?? versionsDoc?.document_type_label ?? null}
            />
        </Main>
    );
}
