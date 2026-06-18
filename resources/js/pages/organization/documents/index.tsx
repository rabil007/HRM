import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentComplianceTableRow } from '@/features/organization/documents/document-compliance-table-row';
import { DocumentsActiveFilters } from '@/features/organization/documents/documents-active-filters';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsEmptyState } from '@/features/organization/documents/documents-empty-state';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import { DocumentsIndexFolderGrid } from '@/features/organization/documents/index/documents-index-folder-grid';
import { DocumentsIndexSearchResults } from '@/features/organization/documents/index/documents-index-search-results';
import { resolveDocumentsIndexSearchMode } from '@/features/organization/documents/index/use-documents-index-search-mode';
import type { ExpiryFilter } from '@/features/organization/documents/shared/document-expiry';
import { DocumentPreviewDialog } from '@/features/organization/documents/shared/document-preview-dialog';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import type {
    ComplianceDocumentItem,
    DocumentExpirySummary,
    DocumentProfileItem,
    EmployeeFolder,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';
import { DocumentManagementDialogs } from '@/features/organization/documents/shared/document-management-dialogs';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { useDocumentsIndexFilters } from '@/features/organization/documents/use-documents-index-filters';
import { toast } from '@/lib/toast';
import { documents } from '@/routes/organization';

type Props = {
    summary: DocumentExpirySummary;
    expiry: ExpiryFilter;
    search: string;
    employees: EmployeeFolder[];
    searchDocuments: PaginatedComplianceDocuments | null;
    complianceDocuments: PaginatedComplianceDocuments | null;
    can: {
        download: boolean;
        upload: boolean;
        delete: boolean;
    };
};

const EMPTY_SEARCH_DOCUMENTS: PaginatedComplianceDocuments = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    from: null,
    to: null,
};

export default function DocumentsIndex({
    summary,
    expiry: initialExpiry,
    search: initialSearch,
    employees,
    searchDocuments,
    complianceDocuments,
    can,
}: Props) {
    const [previewDoc, setPreviewDoc] = useState<ComplianceDocumentItem | null>(null);
    const [editDoc, setEditDoc] = useState<DocumentProfileItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentProfileItem | null>(null);
    const [versionDoc, setVersionDoc] = useState<DocumentProfileItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [managementEmployeeId, setManagementEmployeeId] = useState<number | null>(null);
    const [isBulkDownloading, setIsBulkDownloading] = useState(false);

    const folderIds = useMemo(
        () => employees.map((employee) => employee.employee_id),
        [employees],
    );

    const {
        selectedIds: selectedFolderIds,
        selectedCount: selectedFolderCount,
        isSelected: isFolderSelected,
        isAllSelected: allFoldersSelected,
        isPartiallySelected: foldersPartiallySelected,
        toggle: toggleFolder,
        toggleAll: toggleAllFolders,
        clear: clearFolderSelection,
    } = useBulkSelection(folderIds);

    const handleBulkFolderDownload = async () => {
        if (selectedFolderIds.length === 0) {
            return;
        }

        setIsBulkDownloading(true);

        try {
            await downloadBulkZip(documents.folders.bulkDownload.url(), {
                employee_ids: selectedFolderIds,
            });
            clearFolderSelection();
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Download failed.');
        } finally {
            setIsBulkDownloading(false);
        }
    };

    const resolvedSearchDocuments = searchDocuments ?? EMPTY_SEARCH_DOCUMENTS;
    const searchPerPage = resolvedSearchDocuments.per_page ?? complianceDocuments?.per_page ?? 25;

    const { searchInput, isSearching, onSearchChange, onExpiryChange, onPageChange } =
        useDocumentsIndexFilters({
            url: documents.url(),
            initialSearch,
            initialExpiry,
            perPage: searchPerPage,
        });

    const isComplianceView = initialExpiry !== 'all';
    const hasSearchQuery = initialSearch.trim() !== '';
    const searchMode = resolveDocumentsIndexSearchMode(
        hasSearchQuery && !isComplianceView,
        employees.length,
        resolvedSearchDocuments.total,
    );

    const folderGridProps = {
        canDownload: can.download,
        isSearching,
        selectedFolderCount,
        isFolderSelected,
        allFoldersSelected,
        foldersPartiallySelected,
        onToggleFolder: toggleFolder,
        onToggleAllFolders: toggleAllFolders,
        onClearFolderSelection: clearFolderSelection,
        onBulkDownload: handleBulkFolderDownload,
        isBulkDownloading,
    };

    const managementPartialReloadKeys = isComplianceView
        ? ['complianceDocuments']
        : ['searchDocuments', 'employees'];

    const bindManagementDoc = (doc: ComplianceDocumentItem) => {
        setManagementEmployeeId(doc.employee_id);

        return doc;
    };

    const handleEditDocument = (doc: ComplianceDocumentItem) => {
        setEditDoc(bindManagementDoc(doc));
    };

    const handleReplaceDocument = (doc: ComplianceDocumentItem) => {
        setReplaceDoc(bindManagementDoc(doc));
    };

    const handleVersionDocument = (doc: ComplianceDocumentItem) => {
        setVersionDoc(bindManagementDoc(doc));
    };

    const handleDeleteDocument = (doc: ComplianceDocumentItem) => {
        bindManagementDoc(doc);
        setDeleteDocId(doc.id);
    };

    const documentManagementProps = {
        canDownload: can.download,
        canUpload: can.upload,
        canDelete: can.delete,
        onEdit: handleEditDocument,
        onReplace: handleReplaceDocument,
        onVersions: handleVersionDocument,
        onDelete: handleDeleteDocument,
    };

    return (
        <Main>
            <Head title="Documents" />

            <DocumentsBreadcrumbs items={[{ title: 'Documents' }]} />

            <DocumentsSummaryCards
                summary={summary}
                activeExpiry={initialExpiry}
                onSelect={onExpiryChange}
            />

            <DocumentsActiveFilters
                expiryFilter={initialExpiry}
                onClearExpiry={() => onExpiryChange('all')}
            />

            <div className="sticky top-0 z-20 -mx-1 mb-8 border-b border-border/80 dark:border-white/5 bg-background/95 px-1 pb-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/80">
                <SearchBar
                    placeholder="Search employee, document no, file name..."
                    value={searchInput}
                    onChange={onSearchChange}
                    aria-label="Search documents and employees"
                />
                {isSearching ? (
                    <p className="mt-2 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden />
                        Searching…
                    </p>
                ) : null}
            </div>

            {isComplianceView ? (
                complianceDocuments && complianceDocuments.data.length > 0 ? (
                    <>
                        <OrganizationDataTable minWidth="min-w-[1080px]" compact>
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead className="min-w-[220px]">Document</DataTableHead>
                                    <DataTableHead className="hidden sm:table-cell">Type</DataTableHead>
                                    <DataTableHead className="hidden md:table-cell">Document no.</DataTableHead>
                                    <DataTableHead className="hidden md:table-cell">Expiry</DataTableHead>
                                    <DataTableHead className="hidden lg:table-cell">Remaining</DataTableHead>
                                    <DataTableHead className="hidden sm:table-cell">Status</DataTableHead>
                                    <DataTableHead className="text-right">Actions</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {complianceDocuments.data.map((doc) => (
                                    <DocumentComplianceTableRow
                                        key={doc.id}
                                        doc={doc}
                                        onPreview={setPreviewDoc}
                                        {...documentManagementProps}
                                    />
                                ))}
                            </TableBody>
                        </OrganizationDataTable>

                        <Pagination
                            currentPage={complianceDocuments.current_page}
                            lastPage={complianceDocuments.last_page}
                            from={complianceDocuments.from}
                            to={complianceDocuments.to}
                            total={complianceDocuments.total}
                            perPage={complianceDocuments.per_page}
                            onPageChange={onPageChange}
                            label="documents"
                        />
                    </>
                ) : (
                    <DocumentsEmptyState
                        context="index-compliance"
                        expiryFilter={initialExpiry}
                        hasSearch={hasSearchQuery}
                    />
                )
            ) : searchMode === 'empty' ? (
                <DocumentsEmptyState context="index-search" expiryFilter={initialExpiry} hasSearch />
            ) : searchMode === 'browse' ? (
                employees.length === 0 ? (
                    <DocumentsEmptyState
                        context="index-folders"
                        expiryFilter={initialExpiry}
                        hasSearch={false}
                    />
                ) : (
                    <DocumentsIndexFolderGrid employees={employees} {...folderGridProps} />
                )
            ) : (
                <DocumentsIndexSearchResults
                    mode={searchMode}
                    searchQuery={initialSearch}
                    employees={employees}
                    searchDocuments={resolvedSearchDocuments}
                    onPreview={setPreviewDoc}
                    onPageChange={onPageChange}
                    folderGridProps={folderGridProps}
                    {...documentManagementProps}
                />
            )}

            {managementEmployeeId !== null ? (
                <DocumentManagementDialogs
                    employeeId={managementEmployeeId}
                    editDoc={editDoc}
                    onEditDocChange={setEditDoc}
                    replaceDoc={replaceDoc}
                    onReplaceDocChange={setReplaceDoc}
                    versionDoc={versionDoc}
                    onVersionDocChange={setVersionDoc}
                    deleteDocId={deleteDocId}
                    onDeleteDocIdChange={setDeleteDocId}
                    canDownload={can.download}
                    partialReloadKeys={managementPartialReloadKeys}
                />
            ) : null}

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
