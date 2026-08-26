import { Head, router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { SavedViewsControl } from '@/components/saved-views-control';
import { SearchBar } from '@/components/search-bar';
import { DocumentRequirementSummaryCards } from '@/features/organization/documents/document-requirement-summary-cards';
import { DocumentsActiveFilters } from '@/features/organization/documents/documents-active-filters';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsEmptyState } from '@/features/organization/documents/documents-empty-state';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import type { EmailTemplateOption } from '@/features/organization/documents/email-send/email-template-types';
import { DocumentsIndexDocumentBulkActions } from '@/features/organization/documents/index/documents-index-document-bulk-actions';
import { DocumentsIndexDocumentsTable } from '@/features/organization/documents/index/documents-index-documents-table';
import { DocumentsIndexFolderGrid } from '@/features/organization/documents/index/documents-index-folder-grid';
import { DocumentsIndexRequirementTable } from '@/features/organization/documents/index/documents-index-requirement-table';
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
    DocumentRequirementSummary,
    DocumentTypeOption,
    EmployeeFolder,
    PaginatedComplianceDocuments,
    PaginatedRequirementDocuments,
    RequirementComplianceItem,
    RequirementStatusFilter,
} from '@/features/organization/documents/shared/types';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { useDocumentsIndexFilters } from '@/features/organization/documents/use-documents-index-filters';
import { FolderShareLinksModal } from '@/features/organization/documents/whatsapp-share';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import type { SavedView } from '@/lib/saved-views';
import { toast } from '@/lib/toast';
import { UploadDocumentDialog } from '@/pages/organization/_components/documents/upload-dialog';
import { documents } from '@/routes/organization';
import documentRoutes from '@/routes/organization/documents';
import { shareLinks as folderShareLinks } from '@/routes/organization/documents/folders';

type Props = {
    summary: DocumentExpirySummary;
    requirement_summary?: DocumentRequirementSummary;
    expiry: ExpiryFilter;
    requirement_status?: RequirementStatusFilter;
    search: string;
    department_id?: string;
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
    employees: EmployeeFolder[];
    searchDocuments: PaginatedComplianceDocuments | null;
    complianceDocuments: PaginatedComplianceDocuments | null;
    requirementDocuments?: PaginatedRequirementDocuments | null;
    document_types: DocumentTypeOption[];
    countries: PhoneCountryOption[];
    can: {
        download: boolean;
        share: boolean;
        upload: boolean;
        delete: boolean;
        whatsapp_template: boolean;
        whatsapp_templates: WhatsAppTemplateOption[];
        email_templates: EmailTemplateOption[];
    };
    saved_views?: SavedView[];
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

const EMPTY_REQUIREMENT_SUMMARY: DocumentRequirementSummary = {
    required: 0,
    valid: 0,
    expiring: 0,
    expired: 0,
    missing: 0,
};

export default function DocumentsIndex({
    summary,
    requirement_summary = EMPTY_REQUIREMENT_SUMMARY,
    expiry: initialExpiry,
    requirement_status: initialRequirementStatus = '',
    search: initialSearch,
    department_id: initialDepartmentId = '',
    department_tree = [],
    department_tree_selected_id = null,
    employees,
    searchDocuments,
    complianceDocuments,
    requirementDocuments = null,
    document_types,
    countries = [],
    can,
    saved_views = [],
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
    const [folderShareModalOpen, setFolderShareModalOpen] = useState(false);
    const [uploadRequirement, setUploadRequirement] =
        useState<RequirementComplianceItem | null>(null);

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
        onRequirementStatusChange,
        onDepartmentChange,
        onPageChange,
    } = useDocumentsIndexFilters({
        url: documents.url(),
        initialSearch,
        initialExpiry,
        initialRequirementStatus,
        initialDepartmentId,
        perPage: searchPerPage,
    });

    const isRequirementView = initialRequirementStatus !== '';
    const isComplianceView = initialExpiry !== 'all' && !isRequirementView;
    const hasSearchQuery = initialSearch.trim() !== '';
    const searchMode = resolveDocumentsIndexSearchMode(
        hasSearchQuery && !isComplianceView && !isRequirementView,
        employees.length,
        resolvedSearchDocuments.total,
    );

    const visibleDocuments = useMemo((): ComplianceDocumentItem[] => {
        if (isComplianceView) {
            return complianceDocuments?.data ?? [];
        }

        if (hasSearchQuery) {
            return resolvedSearchDocuments.data;
        }

        return [];
    }, [
        isComplianceView,
        complianceDocuments,
        hasSearchQuery,
        resolvedSearchDocuments.data,
    ]);

    const visibleDocumentIds = useMemo(
        () => visibleDocuments.map((document) => document.id),
        [visibleDocuments],
    );

    const {
        selectedIds: selectedDocumentIds,
        isSelected: isDocumentSelected,
        isAllSelected: allDocumentsSelected,
        isPartiallySelected: documentsPartiallySelected,
        toggle: toggleDocument,
        toggleAll: toggleAllDocuments,
        clear: clearDocumentSelection,
    } = useBulkSelection(visibleDocumentIds);

    const selectedDocuments = useMemo(
        () =>
            visibleDocuments.filter((document) =>
                selectedDocumentIds.includes(document.id),
            ),
        [visibleDocuments, selectedDocumentIds],
    );

    const documentSelectionProps = {
        selectionMode: true as const,
        isSelected: isDocumentSelected,
        allSelected: allDocumentsSelected,
        partiallySelected: documentsPartiallySelected,
        onToggle: toggleDocument,
        onToggleAll: toggleAllDocuments,
    };

    const folderGridProps = {
        canDownload: can.download,
        canShare: can.share,
        isSearching,
        selectedFolderCount,
        isFolderSelected,
        allFoldersSelected,
        foldersPartiallySelected,
        onToggleFolder: toggleFolder,
        onToggleAllFolders: toggleAllFolders,
        onClearFolderSelection: clearFolderSelection,
        onBulkDownload: handleBulkFolderDownload,
        onBulkShare: () => setFolderShareModalOpen(true),
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
                activeExpiry={isRequirementView ? null : initialExpiry}
                onSelect={onExpiryChange}
            />

            <DocumentRequirementSummaryCards
                summary={requirement_summary}
                activeStatus={initialRequirementStatus}
                onSelect={onRequirementStatusChange}
            />

            <DocumentsActiveFilters
                expiryFilter={initialExpiry}
                requirementStatus={initialRequirementStatus}
                search={initialSearch}
                departmentSelected={Boolean(department_tree_selected_id)}
                onClearExpiry={() => onExpiryChange('all')}
                onClearRequirement={() => onRequirementStatusChange('')}
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
                        <div className="flex items-center gap-2">
                            {department_tree.length > 0 ? (
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
                            ) : null}
                            <SavedViewsControl
                                pageKey="documents"
                                indexUrl={documents.url()}
                                currentFilters={{
                                    search: initialSearch,
                                    expiry: initialExpiry,
                                    requirement_status:
                                        initialRequirementStatus,
                                    department_id: initialDepartmentId,
                                }}
                                views={saved_views}
                            />
                        </div>
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

            {!isRequirementView ? (
                <DocumentsIndexDocumentBulkActions
                    selectedDocumentIds={selectedDocumentIds}
                    selectedDocuments={selectedDocuments}
                    onClear={clearDocumentSelection}
                    can={can}
                    countries={countries}
                />
            ) : null}

            {isRequirementView ? (
                requirementDocuments && requirementDocuments.data.length > 0 ? (
                    <DocumentsIndexRequirementTable
                        documents={requirementDocuments}
                        canUpload={can.upload}
                        onPageChange={onPageChange}
                        onUpload={(item) => setUploadRequirement(item)}
                        onView={(item) => {
                            if (item.document_id === null) {
                                return;
                            }

                            router.visit(
                                buildDocumentShowUrl(
                                    item.employee_id,
                                    item.document_id,
                                    {
                                        from: 'index',
                                        search: initialSearch,
                                        page: requirementDocuments.current_page,
                                    },
                                ),
                            );
                        }}
                        onReplace={(item) => {
                            if (item.document_id === null) {
                                return;
                            }

                            router.visit(
                                buildDocumentShowUrl(
                                    item.employee_id,
                                    item.document_id,
                                    {
                                        from: 'index',
                                        search: initialSearch,
                                        page: requirementDocuments.current_page,
                                    },
                                ),
                            );
                        }}
                    />
                ) : (
                    <DocumentsEmptyState
                        context="index-requirement"
                        expiryFilter={initialExpiry}
                        hasSearch={hasSearchQuery}
                    />
                )
            ) : isComplianceView ? (
                complianceDocuments && complianceDocuments.data.length > 0 ? (
                    <DocumentsIndexDocumentsTable
                        documents={complianceDocuments}
                        onPageChange={onPageChange}
                        {...documentManagementProps}
                        {...documentSelectionProps}
                    />
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
                    {...documentSelectionProps}
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

            {can.share ? (
                <FolderShareLinksModal
                    open={folderShareModalOpen}
                    onOpenChange={setFolderShareModalOpen}
                    employeeIds={selectedFolderIds}
                    shareLinksUrl={folderShareLinks.url()}
                    onComplete={clearFolderSelection}
                />
            ) : null}
            {uploadRequirement !== null ? (
                <UploadDocumentDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setUploadRequirement(null);
                        }
                    }}
                    employeeId={uploadRequirement.employee_id}
                    employeeName={uploadRequirement.employee_name}
                    documentTypes={document_types}
                    initialDocumentTypeId={uploadRequirement.document_type_id}
                    partialReloadKeys={[
                        'requirementDocuments',
                        'requirement_summary',
                        'summary',
                        'employees',
                    ]}
                />
            ) : null}
        </Main>
    );
}
