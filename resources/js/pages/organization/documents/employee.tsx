import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, FileStack, FolderOpen, Loader2, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentsActiveFilters } from '@/features/organization/documents/documents-active-filters';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsEmptyState } from '@/features/organization/documents/documents-empty-state';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import { EmployeeDocumentTableRow } from '@/features/organization/documents/employee-document-table-row';
import { filterDocuments } from '@/features/organization/documents/filter-documents';
import { filterDocumentsByExpiry } from '@/features/organization/documents/filter-documents-by-expiry';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import type { ExpiryFilter } from '@/features/organization/documents/shared/document-expiry';
import { DocumentPreviewDialog } from '@/features/organization/documents/shared/document-preview-dialog';
import { downloadBinaryExport } from '@/features/organization/documents/shared/download-binary-export';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import type {
    DocumentBrowseItem,
    DocumentExpirySummary,
    EmployeeSummary,
} from '@/features/organization/documents/shared/types';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { toast } from '@/lib/toast';
import { documents } from '@/routes/organization';
import { mergePdf } from '@/routes/organization/documents/employee/files';
import { show } from '@/routes/organization/employees';

type Props = {
    employee: EmployeeSummary;
    documents: DocumentBrowseItem[];
    summary: DocumentExpirySummary;
};

export default function EmployeeDocumentsBrowse({
    employee,
    documents: allDocuments,
    summary,
}: Props) {
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const canDeleteDocuments = (auth?.permissions ?? []).includes('employees.documents.delete');

    const [previewDoc, setPreviewDoc] = useState<DocumentBrowseItem | null>(null);
    const [fileSearch, setFileSearch] = useState('');
    const [expiryFilter, setExpiryFilter] = useState<ExpiryFilter>('all');
    const [isBulkDownloading, setIsBulkDownloading] = useState(false);
    const [isMerging, setIsMerging] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const filteredDocuments = useMemo(() => {
        const byExpiry = filterDocumentsByExpiry(allDocuments, expiryFilter);

        return filterDocuments(byExpiry, fileSearch);
    }, [allDocuments, expiryFilter, fileSearch]);

    const visibleDocumentIds = useMemo(
        () => filteredDocuments.map((document) => document.id),
        [filteredDocuments],
    );

    const {
        selectedIds: selectedDocumentIds,
        selectedCount: selectedDocumentCount,
        isSelected: isDocumentSelected,
        isAllSelected: allDocumentsSelected,
        isPartiallySelected: documentsPartiallySelected,
        toggle: toggleDocument,
        toggleAll: toggleAllDocuments,
        clear: clearDocumentSelection,
    } = useBulkSelection(visibleDocumentIds);

    const profileDocumentsUrl = `${show.url({ employee: employee.id })}#documents`;

    const handleBulkDownload = async () => {
        if (selectedDocumentIds.length === 0) {
            return;
        }

        setIsBulkDownloading(true);

        try {
            await downloadBulkZip(documents.files.bulkDownload.url(), {
                document_ids: selectedDocumentIds,
            });
            clearDocumentSelection();
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Download failed.');
        } finally {
            setIsBulkDownloading(false);
        }
    };

    const handleMergePdfs = async () => {
        const selectedDocs = filteredDocuments.filter((document) =>
            selectedDocumentIds.includes(document.id),
        );

        if (selectedDocs.length < 2) {
            toast.error('Select at least 2 PDF files to merge.');

            return;
        }

        if (selectedDocs.some((document) => document.mime_type !== 'application/pdf')) {
            toast.error('All selected files must be PDF documents.');

            return;
        }

        setIsMerging(true);

        try {
            await downloadBinaryExport(
                mergePdf.url({ employee: employee.id }),
                { document_ids: selectedDocumentIds },
                'application/pdf',
                `${employee.name.replace(/\s+/g, '_')}_DOCUMENTS.pdf`,
                'Merge failed. Please try again.',
            );
            clearDocumentSelection();
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Merge failed. Please try again.');
        } finally {
            setIsMerging(false);
        }
    };

    const handleBulkDelete = () => {
        if (selectedDocumentIds.length === 0) {
            return;
        }

        setIsDeleting(true);

        router.delete(documents.employee.files.bulkDestroy.url({ employee: employee.id }), {
            data: { document_ids: selectedDocumentIds },
            preserveScroll: true,
            onSuccess: () => {
                clearDocumentSelection();
                setDeleteDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to delete selected documents.');
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <Main>
            <Head title={`Documents — ${employee.name}`} />

            <DocumentsBreadcrumbs
                items={[
                    { title: 'Documents', href: documents.url() },
                    { title: employee.name },
                ]}
            />

            <DocumentsSummaryCards
                summary={summary}
                activeExpiry={expiryFilter}
                onSelect={setExpiryFilter}
            />

            <DocumentsActiveFilters
                expiryFilter={expiryFilter}
                search={fileSearch}
                onClearExpiry={() => setExpiryFilter('all')}
                onClearSearch={() => setFileSearch('')}
            />

            {allDocuments.length > 0 ? (
                <SearchBar
                    className="mb-6"
                    placeholder="Search files by name, type, or date…"
                    value={fileSearch}
                    onChange={setFileSearch}
                />
            ) : null}

            <DocumentsBulkToolbar
                count={selectedDocumentCount}
                itemLabel="files"
                onClear={clearDocumentSelection}
                actions={
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="rounded-lg"
                            disabled={isBulkDownloading}
                            onClick={handleBulkDownload}
                        >
                            {isBulkDownloading ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Download className="mr-2 h-4 w-4" />
                            )}
                            Download
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="rounded-lg"
                            disabled={isMerging}
                            onClick={handleMergePdfs}
                        >
                            {isMerging ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <FileStack className="mr-2 h-4 w-4" />
                            )}
                            Merge PDFs
                        </Button>
                        {canDeleteDocuments ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-lg text-red-400/80 hover:bg-red-500/10 hover:text-red-400"
                                disabled={isDeleting}
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                <Trash2 className="mr-2 h-4 w-4" />
                                Delete
                            </Button>
                        ) : null}
                    </>
                }
            />

            {allDocuments.length === 0 ? (
                <DocumentsEmptyState
                    context="employee-files"
                    expiryFilter="all"
                    hasSearch={false}
                    action={
                        <Button variant="outline" size="sm" className="rounded-lg" asChild>
                            <Link href={profileDocumentsUrl}>
                                <FolderOpen className="mr-2 h-4 w-4" />
                                Go to employee profile
                            </Link>
                        </Button>
                    }
                />
            ) : filteredDocuments.length === 0 ? (
                <DocumentsEmptyState
                    context="employee-files"
                    expiryFilter={expiryFilter}
                    hasSearch={fileSearch.trim() !== ''}
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[1040px]" compact>
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="w-10 px-3">
                                <Checkbox
                                    checked={
                                        allDocumentsSelected
                                            ? true
                                            : documentsPartiallySelected
                                              ? 'indeterminate'
                                              : false
                                    }
                                    onCheckedChange={toggleAllDocuments}
                                    aria-label="Select all files"
                                />
                            </DataTableHead>
                            <DataTableHead className="min-w-[240px]">File</DataTableHead>
                            <DataTableHead className="hidden sm:table-cell">Type</DataTableHead>
                            <DataTableHead className="hidden md:table-cell">Issue date</DataTableHead>
                            <DataTableHead className="hidden lg:table-cell">Expiry</DataTableHead>
                            <DataTableHead className="hidden md:table-cell">File size</DataTableHead>
                            <DataTableHead className="hidden lg:table-cell">Status</DataTableHead>
                            <DataTableHead className="hidden xl:table-cell">Uploaded</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {filteredDocuments.map((doc) => (
                            <EmployeeDocumentTableRow
                                key={doc.id}
                                doc={doc}
                                onPreview={setPreviewDoc}
                                selectionMode
                                selected={isDocumentSelected(doc.id)}
                                onSelectedChange={() => toggleDocument(doc.id)}
                            />
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            <ConfirmDeleteDocumentDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete selected documents"
                description={
                    <>
                        Are you sure you want to delete {selectedDocumentCount} selected{' '}
                        {selectedDocumentCount === 1 ? 'document' : 'documents'}? This action cannot be undone.
                    </>
                }
                confirmLabel={isDeleting ? 'Deleting…' : 'Delete'}
                confirmDisabled={isDeleting}
                contentClassName="glass-card"
                cancelClassName="glass-card rounded-xl hover:bg-accent"
                confirmClassName="rounded-xl bg-red-600 hover:bg-red-600/90"
                onConfirm={handleBulkDelete}
            />

            <DocumentPreviewDialog
                document={
                    previewDoc
                        ? {
                              title: previewDoc.document_name,
                              document_type_label: previewDoc.document_type,
                              file_url: previewDoc.file_url,
                              mime_type: previewDoc.mime_type,
                              can_preview: previewDoc.can_preview,
                          }
                        : null
                }
                onOpenChange={(open) => !open && setPreviewDoc(null)}
            />
        </Main>
    );
}
