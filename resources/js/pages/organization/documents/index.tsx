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
import { DocumentManagementDialogs } from '@/features/organization/documents/shared/document-management-dialogs';
import { buildDocumentShowUrl } from '@/features/organization/documents/shared/document-show-url';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import type {
    ComplianceDocumentItem,
    DocumentExpirySummary,
    DocumentProfileItem,
    DocumentTypeOption,
    EmployeeFolder,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { useDocumentsIndexFilters } from '@/features/organization/documents/use-documents-index-filters';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import { documents } from '@/routes/organization';
import documentRoutes from '@/routes/organization/documents';

type Props = {
    summary: DocumentExpirySummary;
    expiry: ExpiryFilter;
    search: string;
    department_id?: string;
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
    employees: EmployeeFolder[];
    searchDocuments: PaginatedComplianceDocuments | null;
    complianceDocuments: PaginatedComplianceDocuments | null;
    document_types: DocumentTypeOption[];
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
    department_id: initialDepartmentId = '',
    department_tree = [],
    department_tree_selected_id = null,
    employees,
    searchDocuments,
    complianceDocuments,
    document_types,
    can,
}: Props) {
    const [editDoc, setEditDoc] = useState<DocumentProfileItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentProfileItem | null>(
        null,
    );
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [managementEmployeeId, setManagementEmployeeId] = useState<
        number | null
    >(null);
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
            await downloadBulkZip(documentRoutes.folders.bulkDownload.url(), {
                employee_ids: selectedFolderIds,
            });
            clearFolderSelection();
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Download failed.',
            );
        } finally {
            setIsBulkDownloading(false);
        }
    };

    const resolvedSearchDocuments = searchDocuments ?? EMPTY_SEARCH_DOCUMENTS;
    const searchPerPage =
        resolvedSearchDocuments.per_page ?? complianceDocuments?.per_page ?? 25;

    const {
        searchInput,
        isSearching,
        onSearchChange,
        onExpiryChange,
        onDepartmentChange,
        onPageChange,
    } = useDocumentsIndexFilters({
        url: documents.url(),
        initialSearch,
        initialExpiry,
        initialDepartmentId,
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

    const handleDeleteDocument = (doc: ComplianceDocumentItem) => {
        bindManagementDoc(doc);
        setDeleteDocId(doc.id);
    };

    const buildViewHref = (doc: ComplianceDocumentItem) =>
        buildDocumentShowUrl(doc.employee_id, doc.id, {
            from: 'index',
            expiry: initialExpiry,
            search: initialSearch,
            page: isComplianceView
                ? complianceDocuments?.current_page
                : resolvedSearchDocuments.current_page,
        });

    const documentManagementProps = {
        canDownload: can.download,
        canUpload: can.upload,
        canDelete: can.delete,
        buildViewHref,
        onEdit: handleEditDocument,
        onReplace: handleReplaceDocument,
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
                search={initialSearch}
                departmentSelected={Boolean(department_tree_selected_id)}
                onClearExpiry={() => onExpiryChange('all')}
                onClearSearch={() => onSearchChange('')}
                onClearDepartment={() => onDepartmentChange(null)}
            />

            <div className="sticky top-0 z-20 -mx-1 mb-8 border-b border-border/80 bg-background/95 px-1 pb-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/80 dark:border-white/5">
                <SearchBar
                    placeholder="Search employee, document no, file name..."
                    value={searchInput}
                    onChange={onSearchChange}
                    aria-label="Search documents and employees"
                    right={
                        department_tree.length > 0 ? (
                            <DepartmentFilterControls
                                department_tree={department_tree}
                                department_tree_selected_id={
                                    department_tree_selected_id
                                }
                                department_tree_selected_position_id={null}
                                onSelectDepartment={onDepartmentChange}
                                onSelectPosition={(_, departmentId) =>
                                    onDepartmentChange(departmentId)
                                }
                            />
                        ) : null
                    }
                />
                {isSearching ? (
                    <p className="mt-2 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Loader2
                            className="h-3.5 w-3.5 animate-spin"
                            aria-hidden
                        />
                        Searching…
                    </p>
                ) : null}
            </div>

            {isComplianceView ? (
                complianceDocuments && complianceDocuments.data.length > 0 ? (
                    <>
                        <OrganizationDataTable
                            minWidth="min-w-[1080px]"
                            compact
                        >
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead className="min-w-[220px]">
                                        Document
                                    </DataTableHead>
                                    <DataTableHead className="hidden sm:table-cell">
                                        Type
                                    </DataTableHead>
                                    <DataTableHead className="hidden md:table-cell">
                                        Document no.
                                    </DataTableHead>
                                    <DataTableHead className="hidden md:table-cell">
                                        Expiry
                                    </DataTableHead>
                                    <DataTableHead className="hidden lg:table-cell">
                                        Remaining
                                    </DataTableHead>
                                    <DataTableHead className="hidden sm:table-cell">
                                        Status
                                    </DataTableHead>
                                    <DataTableHead className="text-right">
                                        Actions
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {complianceDocuments.data.map((doc) => (
                                    <DocumentComplianceTableRow
                                        key={doc.id}
                                        doc={doc}
                                        viewHref={buildViewHref(doc)}
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
                <DocumentsEmptyState
                    context="index-search"
                    expiryFilter={initialExpiry}
                    hasSearch
                />
            ) : searchMode === 'browse' ? (
                employees.length === 0 ? (
                    <DocumentsEmptyState
                        context="index-folders"
                        expiryFilter={initialExpiry}
                        hasSearch={false}
                    />
                ) : (
                    <DocumentsIndexFolderGrid
                        employees={employees}
                        {...folderGridProps}
                    />
                )
            ) : (
                <DocumentsIndexSearchResults
                    mode={searchMode}
                    searchQuery={initialSearch}
                    employees={employees}
                    searchDocuments={resolvedSearchDocuments}
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
                    deleteDocId={deleteDocId}
                    onDeleteDocIdChange={setDeleteDocId}
                    partialReloadKeys={managementPartialReloadKeys}
                    documentTypes={document_types}
                />
            ) : null}
        </Main>
    );
}
