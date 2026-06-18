import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { Pagination } from '@/components/pagination';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentComplianceTableRow } from '@/features/organization/documents/document-compliance-table-row';
import type {
    ComplianceDocumentItem,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';

export function DocumentsIndexDocumentsTable({
    documents,
    buildViewHref,
    onPageChange,
    canDownload,
    canUpload,
    canDelete,
    onEdit,
    onReplace,
    onDelete,
}: {
    documents: PaginatedComplianceDocuments;
    buildViewHref: (doc: ComplianceDocumentItem) => string;
    onPageChange?: (page: number) => void;
    canDownload: boolean;
    canUpload: boolean;
    canDelete: boolean;
    onEdit: (doc: ComplianceDocumentItem) => void;
    onReplace: (doc: ComplianceDocumentItem) => void;
    onDelete: (doc: ComplianceDocumentItem) => void;
}) {
    if (documents.data.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
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
                    {documents.data.map((doc) => (
                        <DocumentComplianceTableRow
                            key={doc.id}
                            doc={doc}
                            viewHref={buildViewHref(doc)}
                            canDownload={canDownload}
                            canUpload={canUpload}
                            canDelete={canDelete}
                            onEdit={onEdit}
                            onReplace={onReplace}
                            onDelete={onDelete}
                        />
                    ))}
                </TableBody>
            </OrganizationDataTable>

            {documents.last_page > 1 && onPageChange ? (
                <Pagination
                    currentPage={documents.current_page}
                    lastPage={documents.last_page}
                    from={documents.from}
                    to={documents.to}
                    total={documents.total}
                    perPage={documents.per_page}
                    onPageChange={onPageChange}
                    label="documents"
                />
            ) : null}
        </div>
    );
}
