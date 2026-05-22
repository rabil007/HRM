import { Head } from '@inertiajs/react';
import { Download, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentComplianceTableRow } from '@/features/organization/documents/document-compliance-table-row';
import { DocumentsActiveFilters } from '@/features/organization/documents/documents-active-filters';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsEmptyState } from '@/features/organization/documents/documents-empty-state';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import type { ExpiryFilter } from '@/features/organization/documents/shared/document-expiry';
import { DocumentPreviewDialog } from '@/features/organization/documents/shared/document-preview-dialog';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import type {
    ComplianceDocumentItem,
    DocumentExpirySummary,
    EmployeeFolder,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { useDocumentsIndexFilters } from '@/features/organization/documents/use-documents-index-filters';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
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
        delete: boolean;
    };
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

    const searchPerPage = searchDocuments?.per_page ?? complianceDocuments?.per_page ?? 25;

    const { searchInput, isSearching, onSearchChange, onExpiryChange, onPageChange } =
        useDocumentsIndexFilters({
            url: documents.url(),
            initialSearch,
            initialExpiry,
            perPage: searchPerPage,
        });

    const isComplianceView = initialExpiry !== 'all';
    const hasSearchQuery = initialSearch.trim() !== '';
    const hasSearchFileResults = (searchDocuments?.data.length ?? 0) > 0;
    const folderLabel =
        employees.length === 1 ? '1 employee folder' : `${employees.length} employee folders`;
    const searchFilesLabel =
        searchDocuments?.total === 1
            ? '1 matching file'
            : `${searchDocuments?.total ?? 0} matching files`;

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
                onClearExpiry={() => onExpiryChange('all')}
                onClearSearch={() => onSearchChange('')}
            />

            <div className="mb-6 space-y-4">
                <SearchBar
                    placeholder="Search by employee, document number, title, or type…"
                    value={searchInput}
                    onChange={onSearchChange}
                />

                {!isComplianceView && hasSearchQuery && hasSearchFileResults ? (
                    <span className="text-sm font-medium text-muted-foreground">{searchFilesLabel}</span>
                ) : null}

                {!isComplianceView && employees.length > 0 ? (
                    <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                        <span className="font-medium">{folderLabel}</span>
                        {isSearching ? (
                            <span className="inline-flex items-center gap-1.5 text-xs">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                Updating…
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>

            {isComplianceView ? (
                complianceDocuments && complianceDocuments.data.length > 0 ? (
                    <>
                        <OrganizationDataTable minWidth="min-w-[960px]" compact>
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
                                        canDownload={can.download}
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
                        hasSearch={initialSearch.trim() !== ''}
                    />
                )
            ) : employees.length === 0 && !hasSearchFileResults ? (
                <DocumentsEmptyState
                    context="index-folders"
                    expiryFilter={initialExpiry}
                    hasSearch={hasSearchQuery}
                />
            ) : (
                <>
                    {!isComplianceView && hasSearchQuery && searchDocuments && hasSearchFileResults ? (
                        <div className="mb-8 space-y-4">
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
                                    {searchDocuments.data.map((doc) => (
                                        <DocumentComplianceTableRow
                                            key={doc.id}
                                            doc={doc}
                                            onPreview={setPreviewDoc}
                                            canDownload={can.download}
                                        />
                                    ))}
                                </TableBody>
                            </OrganizationDataTable>

                            {searchDocuments.last_page > 1 ? (
                                <Pagination
                                    currentPage={searchDocuments.current_page}
                                    lastPage={searchDocuments.last_page}
                                    from={searchDocuments.from}
                                    to={searchDocuments.to}
                                    total={searchDocuments.total}
                                    perPage={searchDocuments.per_page}
                                    onPageChange={onPageChange}
                                    label="files"
                                />
                            ) : null}
                        </div>
                    ) : null}

                    {employees.length > 0 ? (
                        <>
                    <DocumentsBulkToolbar
                        count={selectedFolderCount}
                        itemLabel="folders"
                        onClear={clearFolderSelection}
                        selectAll={
                            <Checkbox
                                checked={
                                    allFoldersSelected
                                        ? true
                                        : foldersPartiallySelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={toggleAllFolders}
                                aria-label="Select all folders"
                            />
                        }
                        actions={
                            can.download ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg"
                                    disabled={isBulkDownloading}
                                    onClick={handleBulkFolderDownload}
                                >
                                    {isBulkDownloading ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Download className="mr-2 h-4 w-4" />
                                    )}
                                    Download ZIP
                                </Button>
                            ) : null
                        }
                    />

                    <section
                        className={cn(
                            'rounded-xl border border-white/5 bg-white/[0.02] p-4 sm:p-6',
                            'transition-opacity duration-200',
                            isSearching && 'pointer-events-none opacity-60',
                        )}
                        aria-busy={isSearching}
                    >
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-x-3 gap-y-6 sm:grid-cols-[repeat(auto-fill,minmax(8.25rem,1fr))] sm:gap-x-5 sm:gap-y-8">
                            {employees.map((employee) => (
                                <EmployeeFolderItem
                                    key={employee.employee_id}
                                    employee={employee}
                                    canDownload={can.download}
                                    selectionMode
                                    selected={isFolderSelected(employee.employee_id)}
                                    onSelectedChange={() => toggleFolder(employee.employee_id)}
                                />
                            ))}
                        </div>
                    </section>
                        </>
                    ) : null}
                </>
            )}

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
