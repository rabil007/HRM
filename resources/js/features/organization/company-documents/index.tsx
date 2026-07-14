import { Link, router } from '@inertiajs/react';
import {
    Download,
    Eye,
    FileClock,
    FilePenLine,
    History,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
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
                    title="Preview"
                    onClick={onPreview}
                >
                    <Eye className="h-4 w-4" />
                </Button>
            ) : null}
            {can.download ? (
                <Button asChild size="icon" variant="ghost" title="Download">
                    <a href={document.download_url}>
                        <Download className="h-4 w-4" />
                    </a>
                </Button>
            ) : null}
            <Button
                size="icon"
                variant="ghost"
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
                        title="Edit metadata"
                        onClick={onEdit}
                    >
                        <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
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
                    className="text-destructive"
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
        ['All documents', summary.total, FilePenLine],
        ['Valid', summary.valid, FileClock],
        ['Expiring soon', summary.expiring_soon, FileClock],
        ['Expired', summary.expired, FileClock],
    ] as const;

    return (
        <Main>
            <PageHeader
                title={`${company.name} documents`}
                description="Private compliance files, metadata, expiry tracking, and version history."
                right={
                    <>
                        <Button variant="outline" asChild>
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
                                    onClick={() => setBulkOpen(true)}
                                >
                                    <Upload className="mr-2 h-4 w-4" />{' '}
                                    Multi-upload
                                </Button>
                                <Button
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

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {summaryCards.map(([label, count, Icon]) => (
                    <Card key={label}>
                        <CardContent className="flex items-center justify-between p-5">
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-1 text-2xl font-bold">
                                    {count}
                                </p>
                            </div>
                            <Icon className="h-5 w-5 text-muted-foreground" />
                        </CardContent>
                    </Card>
                ))}
            </div>

            <SearchBar
                value={list.searchInput}
                onChange={list.onSearchChange}
                placeholder="Search title, number, filename, or type..."
                right={
                    <>
                        <select
                            value={filters.document_type ?? ''}
                            onChange={(event) =>
                                list.applyFilters({
                                    document_type: event.target.value,
                                    expiry_status: filters.expiry_status,
                                })
                            }
                            className="h-12 rounded-xl border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All types</option>
                            {document_types.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.title}
                                </option>
                            ))}
                        </select>
                        <select
                            value={filters.expiry_status}
                            onChange={(event) =>
                                list.applyFilters({
                                    document_type: filters.document_type ?? '',
                                    expiry_status: event.target.value,
                                })
                            }
                            className="h-12 rounded-xl border border-input bg-background px-3 text-sm"
                        >
                            <option value="all">All expiry statuses</option>
                            <option value="valid">Valid</option>
                            <option value="expiring_soon">Expiring soon</option>
                            <option value="expired">Expired</option>
                        </select>
                        <ViewToggle value={view} onChange={setView} />
                    </>
                }
            />

            {documents.length === 0 ? (
                <EmptyState title="No company documents found." />
            ) : view === 'grid' ? (
                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {documents.map((document) => (
                        <Card key={document.id} className="overflow-hidden">
                            <CardHeader className="pb-3">
                                <div className="flex items-start gap-3">
                                    <div className="rounded-xl bg-muted p-3">
                                        <DocumentFileIcon
                                            mimeType={document.mime_type}
                                            fileName={
                                                document.original_filename
                                            }
                                        />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <CardTitle className="truncate text-base">
                                            {document.title}
                                        </CardTitle>
                                        <p className="mt-1 truncate text-xs text-muted-foreground">
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
                                <div className="space-y-2 text-xs text-muted-foreground">
                                    <p className="truncate">
                                        {document.original_filename} ·{' '}
                                        {fileSize(document.size_bytes)}
                                    </p>
                                    <p>
                                        Number:{' '}
                                        {document.document_number ?? '—'}
                                    </p>
                                    <p>
                                        Expires:{' '}
                                        {document.expiry_date
                                            ? formatDisplayDate(
                                                  document.expiry_date,
                                              )
                                            : 'No expiry'}
                                    </p>
                                    <p>Version {document.current_version}</p>
                                </div>
                                <div className="mt-4 border-t pt-3">
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
                <div className="overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Document</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Expiry</TableHead>
                                <TableHead>Version</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {documents.map((document) => (
                                <TableRow key={document.id}>
                                    <TableCell>
                                        <div className="flex items-center gap-3">
                                            <DocumentFileIcon
                                                mimeType={document.mime_type}
                                                fileName={
                                                    document.original_filename
                                                }
                                            />
                                            <div>
                                                <p className="font-medium">
                                                    {document.title}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {document.original_filename}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {document.document_type?.title ?? '—'}
                                    </TableCell>
                                    <TableCell>
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
                                    <TableCell>
                                        {document.current_version}
                                    </TableCell>
                                    <TableCell>
                                        <DocumentActions
                                            document={document}
                                            can={can}
                                            onPreview={() =>
                                                setPreview(document)
                                            }
                                            onEdit={() =>
                                                openDocument(
                                                    document,
                                                    setFormOpen,
                                                )
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
                    </Table>
                </div>
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
