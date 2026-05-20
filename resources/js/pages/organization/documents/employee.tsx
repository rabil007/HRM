import { Head, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import { EmployeeDocumentTableRow } from '@/features/organization/documents/employee-document-table-row';
import { filterDocuments } from '@/features/organization/documents/filter-documents';
import { filterDocumentsByExpiry } from '@/features/organization/documents/filter-documents-by-expiry';
import type {
    DocumentBrowseItem,
    DocumentExpirySummary,
    EmployeeSummary,
} from '@/features/organization/documents/types';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { documents } from '@/routes/organization';
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
    const [previewDoc, setPreviewDoc] = useState<DocumentBrowseItem | null>(null);
    const [fileSearch, setFileSearch] = useState('');
    const [expiryFilter, setExpiryFilter] = useState<ExpiryFilter>('all');

    const filteredDocuments = useMemo(() => {
        const byExpiry = filterDocumentsByExpiry(allDocuments, expiryFilter);

        return filterDocuments(byExpiry, fileSearch);
    }, [allDocuments, expiryFilter, fileSearch]);

    const profileDocumentsUrl = `${show.url({ employee: employee.id })}#documents`;
    const isFilteredView = expiryFilter !== 'all' || fileSearch.trim() !== '';

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

            {allDocuments.length > 0 ? (
                <SearchBar
                    className="mb-6"
                    placeholder="Search files by name, type, or date…"
                    value={fileSearch}
                    onChange={setFileSearch}
                />
            ) : null}

            {allDocuments.length === 0 ? (
                <EmptyState
                    title="No documents in this folder."
                    description="Upload files from the employee profile to see them here."
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
                <EmptyState
                    title={
                        isFilteredView
                            ? 'No files match your filters.'
                            : 'No files match your search.'
                    }
                    description={
                        expiryFilter !== 'all'
                            ? 'Try another expiry filter or view all documents.'
                            : 'Try a different file name, document type, or upload date.'
                    }
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[800px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="min-w-[220px]">File</DataTableHead>
                            <DataTableHead className="hidden sm:table-cell">Type</DataTableHead>
                            <DataTableHead className="hidden md:table-cell">Issue date</DataTableHead>
                            <DataTableHead className="hidden lg:table-cell">Expiry date</DataTableHead>
                            <DataTableHead className="hidden md:table-cell">File size</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {filteredDocuments.map((doc) => (
                            <EmployeeDocumentTableRow
                                key={doc.id}
                                doc={doc}
                                onPreview={setPreviewDoc}
                            />
                        ))}
                    </TableBody>
                </OrganizationDataTable>
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
