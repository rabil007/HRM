import { Link, router } from '@inertiajs/react';
import {
    Download,
    Eye,
    FileCheck2,
    FileClock,
    FilePenLine,
    FileX2,
    History,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { DocumentPreviewDialog } from '@/features/organization/documents/shared/document-preview-dialog';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { useViewPreference } from '@/hooks/use-view-preference';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    destroy,
    index as companyDocumentsIndex,
} from '@/routes/organization/companies/documents';
import {
    CompanyDocumentBulkUploadDialog,
    CompanyDocumentFormDialog,
    CompanyDocumentReplaceDialog,
    CompanyDocumentVersionsDialog,
} from './company-document-dialogs';
import type { CompanyDocument, CompanyDocumentsPageProps } from './types';

function fileSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(2)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function DocumentActions({
    document,
    can,
    onPreview,
    onEdit,
    onReplace,
    onVersions,
    onDelete,
}: {
    document: CompanyDocument;
    can: CompanyDocumentsPageProps['can'];
    onPreview: () => void;
    onEdit: () => void;
    onReplace: () => void;
    onVersions: () => void;
    onDelete: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center justify-end gap-1">
            {can.download && document.can_preview ? (
                <Button
                    size="icon"
                    variant="ghost"
                    className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                    title="Preview"
                    onClick={onPreview}
                >
                    <Eye className="h-4 w-4" />
                </Button>
            ) : null}
            {can.download ? (
                <Button
                    asChild
                    size="icon"
                    variant="ghost"
                    className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                    title="Download"
                >
                    <a href={document.download_url}>
                        <Download className="h-4 w-4" />
                    </a>
                </Button>
            ) : null}
            <Button
                size="icon"
                variant="ghost"
                className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                title="Version history"
                onClick={onVersions}
            >
                <History className="h-4 w-4" />
            </Button>
            {can.update ? (
                <>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                        title="Edit metadata"
                        onClick={onEdit}
                    >
                        <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                        title="Replace file"
                        onClick={onReplace}
                    >
                        <RefreshCw className="h-4 w-4" />
                    </Button>
                </>
            ) : null}
            {can.delete ? (
                <Button
                    size="icon"
                    variant="ghost"
                    title="Delete"
                    className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                    onClick={onDelete}
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            ) : null}
        </div>
    );
}

export function CompanyDocumentsContent(props: CompanyDocumentsPageProps) {
    const {
        company,
        documents,
        pagination,
        filters,
        summary,
        document_types,
        can,
    } = props;
    const [view, setView] = useViewPreference('company-documents:view', 'grid');
    const [formOpen, setFormOpen] = useState(false);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [replaceOpen, setReplaceOpen] = useState(false);
    const [versionsOpen, setVersionsOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [selected, setSelected] = useState<CompanyDocument | null>(null);
    const [preview, setPreview] = useState<CompanyDocument | null>(null);
    const pageUrl = companyDocumentsIndex.url(company.id);
    const list = useServerPaginationFilters({
        url: pageUrl,
        search: filters.search,
        filters: {
            document_type: filters.document_type ?? '',
            expiry_status: filters.expiry_status,
        },
        pagination,
    });

    const openDocument = (
        document: CompanyDocument,
        setter: (value: boolean) => void,
    ) => {
        setSelected(document);
        setter(true);
    };

    const summaryCards = [
        {
            key: 'all',
            label: 'All documents',
            count: summary.total,
            icon: FilePenLine,
            color: 'text-primary bg-primary/10 border-primary/20',
        },
        {
            key: 'valid',
            label: 'Valid',
            count: summary.valid,
            icon: FileCheck2,
            color: 'text-emerald-600 bg-emerald-500/10 border-emerald-500/20 dark:text-emerald-400',
        },
        {
            key: 'expiring_soon',
            label: 'Expiring soon',
            count: summary.expiring_soon,
            icon: FileClock,
            color: 'text-amber-600 bg-amber-500/10 border-amber-500/20 dark:text-amber-400',
        },
        {
            key: 'expired',
            label: 'Expired',
            count: summary.expired,
            icon: FileX2,
            color: 'text-rose-600 bg-rose-500/10 border-rose-500/20 dark:text-rose-400',
        },
    ] as const;

    return (
        <Main>
            <PageHeader
                title={`${company.name} documents`}
                description="Private compliance files, metadata, expiry tracking, and version history."
                right={
                    <>
                        <Button
                            variant="outline"
                            className="h-11 rounded-xl"
                            asChild
                        >
                            <Link
                                href={`/organization/companies/${company.id}`}
                            >
                                Company details
                            </Link>
                        </Button>
                        {can.upload ? (
                            <>
                                <Button
                                    variant="outline"
                                    className="h-11 rounded-xl"
                                    onClick={() => setBulkOpen(true)}
                                >
                                    <Upload className="mr-2 h-4 w-4" />{' '}
                                    Multi-upload
                                </Button>
                                <Button
                                    className="h-11 rounded-xl shadow-lg shadow-primary/20"
                                    onClick={() => {
                                        setSelected(null);
                                        setFormOpen(true);
                                    }}
                                >
                                    <Plus className="mr-2 h-4 w-4" /> Upload
                                    document
                                </Button>
                            </>
                        ) : null}
                    </>
                }
            />

            {/* Summary Cards */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {summaryCards.map((card) => {
                    const Icon = card.icon;
                    const isActive = filters.expiry_status === card.key;

                    return (
                        <Card
                            key={card.key}
                            className={cn(
                                'cursor-pointer glass-card transition-all duration-200 hover:shadow-md',
                                isActive && 'ring-2 ring-primary/40',
                            )}
                            onClick={() =>
                                list.applyFilters({
                                    document_type: filters.document_type ?? '',
                                    expiry_status:
                                        filters.expiry_status === card.key
                                            ? 'all'
                                            : card.key,
                                })
                            }
                        >
                            <CardContent className="flex items-center justify-between p-5">
                                <div>
                                    <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {card.label}
                                    </p>
                                    <p className="mt-1 text-2xl font-black">
                                        {card.count}
                                    </p>
                                </div>
                                <div
                                    className={cn(
                                        'flex h-11 w-11 items-center justify-center rounded-2xl border',
                                        card.color,
                                    )}
                                >
                                    <Icon className="h-5 w-5" />
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>

            <SearchBar
                value={list.searchInput}
                onChange={list.onSearchChange}
                placeholder="Search title, number, filename, or type..."
                right={
                    <>
                        <div className="min-w-[150px]">
                            <AppSelect
                                value={
                                    filters.document_type
                                        ? String(filters.document_type)
                                        : ''
                                }
                                onValueChange={(val) =>
                                    list.applyFilters({
                                        document_type: val || '',
                                        expiry_status: filters.expiry_status,
                                    })
                                }
                                placeholder="All types"
                            >
                                <AppSelectItem value="">
                                    All types
                                </AppSelectItem>
                                {document_types.map((type) => (
                                    <AppSelectItem
                                        key={type.id}
                                        value={String(type.id)}
                                    >
                                        {type.title}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                        <div className="min-w-[170px]">
                            <AppSelect
                                value={filters.expiry_status || 'all'}
                                onValueChange={(val) =>
                                    list.applyFilters({
                                        document_type:
                                            filters.document_type ?? '',
                                        expiry_status: val || 'all',
                                    })
                                }
                                placeholder="All expiry statuses"
                            >
                                <AppSelectItem value="all">
                                    All expiry statuses
                                </AppSelectItem>
                                <AppSelectItem value="valid">
                                    Valid
                                </AppSelectItem>
                                <AppSelectItem value="expiring_soon">
                                    Expiring soon
                                </AppSelectItem>
                                <AppSelectItem value="expired">
                                    Expired
                                </AppSelectItem>
                            </AppSelect>
                        </div>
                        <ViewToggle value={view} onChange={setView} />
                    </>
                }
            />

            {documents.length === 0 ? (
                <EmptyState title="No company documents found." />
            ) : view === 'grid' ? (
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {documents.map((document) => (
                        <Card
                            key={document.id}
                            className="group relative overflow-hidden glass-card transition-all duration-300 hover:shadow-lg hover:shadow-primary/5"
                        >
                            <CardHeader className="pb-3">
                                <div className="flex items-start gap-3.5">
                                    <div className="rounded-2xl border border-border/80 bg-muted/50 p-3 shadow-xs dark:border-white/10 dark:bg-white/5">
                                        <DocumentFileIcon
                                            mimeType={document.mime_type}
                                            fileName={
                                                document.original_filename
                                            }
                                        />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <CardTitle className="truncate text-base font-bold tracking-tight">
                                            {document.title}
                                        </CardTitle>
                                        <p className="mt-1 truncate text-xs font-medium text-muted-foreground">
                                            {document.document_type?.title ??
                                                'Uncategorized'}
                                        </p>
                                    </div>
                                    <DocumentExpiryBadge
                                        status={document.expiry_status}
                                    />
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2 rounded-xl border border-border/60 bg-muted/30 p-3 text-xs text-muted-foreground dark:border-white/6 dark:bg-white/4">
                                    <p className="truncate font-medium text-foreground/85">
                                        {document.original_filename} ·{' '}
                                        {fileSize(document.size_bytes)}
                                    </p>
                                    <div className="flex items-center justify-between">
                                        <span>Number:</span>
                                        <span className="font-mono font-semibold text-foreground/80">
                                            {document.document_number ?? '—'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span>Expires:</span>
                                        <span className="font-semibold text-foreground/80">
                                            {document.expiry_date
                                                ? formatDisplayDate(
                                                      document.expiry_date,
                                                  )
                                                : 'No expiry'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span>Version:</span>
                                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-bold">
                                            v{document.current_version}
                                        </span>
                                    </div>
                                </div>
                                <div className="mt-4 border-t border-border/60 pt-3 dark:border-white/5">
                                    <DocumentActions
                                        document={document}
                                        can={can}
                                        onPreview={() => setPreview(document)}
                                        onEdit={() =>
                                            openDocument(document, setFormOpen)
                                        }
                                        onReplace={() =>
                                            openDocument(
                                                document,
                                                setReplaceOpen,
                                            )
                                        }
                                        onVersions={() =>
                                            openDocument(
                                                document,
                                                setVersionsOpen,
                                            )
                                        }
                                        onDelete={() =>
                                            openDocument(
                                                document,
                                                setDeleteOpen,
                                            )
                                        }
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[860px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">
                                Document
                            </DataTableHead>
                            <DataTableHead>Type</DataTableHead>
                            <DataTableHead>Expiry</DataTableHead>
                            <DataTableHead>Version</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {documents.map((document) => (
                            <TableRow
                                key={document.id}
                                className={dataTableBodyRowClass()}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-xl border border-border/80 bg-muted/40 p-2 dark:border-white/10 dark:bg-white/5">
                                            <DocumentFileIcon
                                                mimeType={document.mime_type}
                                                fileName={
                                                    document.original_filename
                                                }
                                            />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-foreground">
                                                {document.title}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {document.original_filename} ·{' '}
                                                {fileSize(document.size_bytes)}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {document.document_type?.title ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="space-y-1">
                                        <DocumentExpiryBadge
                                            status={document.expiry_status}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {document.expiry_date
                                                ? formatDisplayDate(
                                                      document.expiry_date,
                                                  )
                                                : 'No expiry'}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-semibold">
                                        v{document.current_version}
                                    </span>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <DocumentActions
                                        document={document}
                                        can={can}
                                        onPreview={() => setPreview(document)}
                                        onEdit={() =>
                                            openDocument(document, setFormOpen)
                                        }
                                        onReplace={() =>
                                            openDocument(
                                                document,
                                                setReplaceOpen,
                                            )
                                        }
                                        onVersions={() =>
                                            openDocument(
                                                document,
                                                setVersionsOpen,
                                            )
                                        }
                                        onDelete={() =>
                                            openDocument(
                                                document,
                                                setDeleteOpen,
                                            )
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            <Pagination {...list.paginationProps} label="documents" />

            <CompanyDocumentFormDialog
                company={company}
                documentTypes={document_types}
                document={selected}
                open={formOpen}
                onOpenChange={setFormOpen}
            />
            <CompanyDocumentBulkUploadDialog
                company={company}
                documentTypes={document_types}
                open={bulkOpen}
                onOpenChange={setBulkOpen}
            />
            <CompanyDocumentReplaceDialog
                company={company}
                document={selected}
                open={replaceOpen}
                onOpenChange={setReplaceOpen}
            />
            <CompanyDocumentVersionsDialog
                company={company}
                document={selected}
                canDownload={can.download}
                open={versionsOpen}
                onOpenChange={setVersionsOpen}
            />
            <DocumentPreviewDialog
                document={
                    preview
                        ? {
                              title: preview.title,
                              file_url: preview.preview_url,
                              mime_type: preview.mime_type,
                              can_preview: preview.can_preview,
                          }
                        : null
                }
                onOpenChange={(open) => {
                    if (!open) {
                        setPreview(null);
                    }
                }}
            />
            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete company document?"
                description="The current file and every historical version will be permanently removed."
                confirmText="Delete document"
                onConfirm={() => {
                    if (!selected) {
                        return;
                    }

                    router.delete(destroy.url([company.id, selected.id]), {
                        preserveScroll: true,
                        onFinish: () => {
                            setDeleteOpen(false);
                            setSelected(null);
                        },
                    });
                }}
            />
        </Main>
    );
}
