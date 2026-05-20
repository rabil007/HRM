import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentComplianceTableRow } from '@/features/organization/documents/document-compliance-table-row';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import type {
    ComplianceDocumentItem,
    DocumentExpirySummary,
    EmployeeFolder,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/types';
import { useDocumentsIndexFilters } from '@/features/organization/documents/use-documents-index-filters';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

type Props = {
    summary: DocumentExpirySummary;
    expiry: ExpiryFilter;
    search: string;
    employees: EmployeeFolder[];
    complianceDocuments: PaginatedComplianceDocuments | null;
};

export default function DocumentsIndex({
    summary,
    expiry: initialExpiry,
    search: initialSearch,
    employees,
    complianceDocuments,
}: Props) {
    const [previewDoc, setPreviewDoc] = useState<ComplianceDocumentItem | null>(null);

    const { searchInput, isSearching, onSearchChange, onExpiryChange, onPageChange } =
        useDocumentsIndexFilters({
            url: documents.url(),
            initialSearch,
            initialExpiry,
            perPage: complianceDocuments?.per_page ?? 25,
        });

    const isComplianceView = initialExpiry !== 'all';
    const folderLabel =
        employees.length === 1 ? '1 employee folder' : `${employees.length} employee folders`;

    return (
        <Main>
            <Head title="Documents" />

            <DocumentsBreadcrumbs items={[{ title: 'Documents' }]} />

            <DocumentsSummaryCards
                summary={summary}
                activeExpiry={initialExpiry}
                onSelect={onExpiryChange}
            />

            <div className="mb-6 space-y-4">
                <SearchBar
                    placeholder={
                        isComplianceView
                            ? 'Search by employee, document name, or type…'
                            : 'Search by employee name or number…'
                    }
                    value={searchInput}
                    onChange={onSearchChange}
                />

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
                        <OrganizationDataTable minWidth="min-w-[960px]">
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Employee</DataTableHead>
                                    <DataTableHead className="min-w-[200px]">Document</DataTableHead>
                                    <DataTableHead className="hidden sm:table-cell">Type</DataTableHead>
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
                    <EmptyState
                        title="No documents match this filter."
                        description={
                            initialSearch
                                ? 'Try a different search or switch to another expiry filter.'
                                : 'No expiry-tracked documents found for this filter.'
                        }
                    />
                )
            ) : employees.length === 0 ? (
                <EmptyState
                    title="No employee folders found."
                    description={
                        initialSearch
                            ? 'Try a different search or upload documents from an employee profile.'
                            : 'Upload documents from an employee profile to see folders here.'
                    }
                />
            ) : (
                <section
                    className={cn(
                        'min-h-[320px] rounded-xl border border-white/5 bg-white/[0.02] p-6 sm:p-8',
                        'transition-opacity duration-200',
                        isSearching && 'pointer-events-none opacity-60',
                    )}
                    aria-busy={isSearching}
                >
                    <div
                        className="grid gap-x-3 gap-y-8 sm:gap-x-5"
                        style={{
                            gridTemplateColumns: 'repeat(auto-fill, minmax(8.75rem, 1fr))',
                        }}
                    >
                        {employees.map((employee) => (
                            <EmployeeFolderItem key={employee.employee_id} employee={employee} />
                        ))}
                    </div>
                </section>
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
