import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import { Pagination } from '@/components/pagination';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentRequirementComplianceTableRow } from '@/features/organization/documents/document-requirement-compliance-table-row';
import type { PaginatedRequirementDocuments } from '@/features/organization/documents/shared/types';

export function DocumentsIndexRequirementTable({
    documents,
    canUpload,
    onPageChange,
    onUpload,
    onView,
    onReplace,
}: {
    documents: PaginatedRequirementDocuments;
    canUpload: boolean;
    onPageChange?: (page: number) => void;
    onUpload: Parameters<
        typeof DocumentRequirementComplianceTableRow
    >[0]['onUpload'];
    onView: Parameters<
        typeof DocumentRequirementComplianceTableRow
    >[0]['onView'];
    onReplace: Parameters<
        typeof DocumentRequirementComplianceTableRow
    >[0]['onReplace'];
}) {
    if (documents.data.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            <OrganizationDataTable minWidth="min-w-[880px]" compact>
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead className="hidden sm:table-cell">
                            Department
                        </DataTableHead>
                        <DataTableHead>Document</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead className="text-right">
                            Action
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {documents.data.map((item) => (
                        <DocumentRequirementComplianceTableRow
                            key={`${item.employee_id}-${item.document_type_id}`}
                            item={item}
                            canUpload={canUpload}
                            onUpload={onUpload}
                            onView={onView}
                            onReplace={onReplace}
                        />
                    ))}
                </TableBody>
            </OrganizationDataTable>
            {onPageChange ? (
                <Pagination
                    currentPage={documents.current_page}
                    lastPage={documents.last_page}
                    from={documents.from}
                    to={documents.to}
                    total={documents.total}
                    perPage={documents.per_page}
                    onPageChange={onPageChange}
                    label="required documents"
                />
            ) : null}
        </div>
    );
}
