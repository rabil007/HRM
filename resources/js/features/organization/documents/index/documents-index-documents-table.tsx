import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
} from '@/components/data-table';
import { MobileRecordList } from '@/components/mobile-record-list';
import { Pagination } from '@/components/pagination';
import { Checkbox } from '@/components/ui/checkbox';
import { TableBody, TableHeader } from '@/components/ui/table';
import { DocumentComplianceMobileCard } from '@/features/organization/documents/components/document-compliance-mobile-card';
import { DocumentComplianceTableRow } from '@/features/organization/documents/document-compliance-table-row';
import type {
    ComplianceDocumentItem,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';
import {
    DESKTOP_OPERATIONAL_TABLE_CLASS,
    MOBILE_OPERATIONAL_LIST_CLASS,
} from '@/lib/mobile-operational-list';

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
    selectionMode = false,
    isSelected,
    allSelected = false,
    partiallySelected = false,
    onToggle,
    onToggleAll,
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
    selectionMode?: boolean;
    isSelected?: (id: number) => boolean;
    allSelected?: boolean;
    partiallySelected?: boolean;
    onToggle?: (id: number) => void;
    onToggleAll?: () => void;
}) {
    if (documents.data.length === 0) {
        return null;
    }

    return (
        <div className="space-y-4">
            <div className={MOBILE_OPERATIONAL_LIST_CLASS}>
                {selectionMode ? (
                    <div className="mb-2 flex items-center gap-2.5 rounded-lg border border-border/50 bg-muted/20 px-3 py-2 dark:border-white/8">
                        <Checkbox
                            checked={
                                allSelected
                                    ? true
                                    : partiallySelected
                                      ? 'indeterminate'
                                      : false
                            }
                            onCheckedChange={onToggleAll}
                            aria-label="Select all documents"
                            id="documents-mobile-select-all"
                        />
                        <label
                            htmlFor="documents-mobile-select-all"
                            className="cursor-pointer text-xs text-muted-foreground select-none"
                        >
                            Select all
                        </label>
                    </div>
                ) : null}
                <MobileRecordList>
                    {documents.data.map((doc) => (
                        <DocumentComplianceMobileCard
                            key={doc.id}
                            doc={doc}
                            viewHref={buildViewHref(doc)}
                            canDownload={canDownload}
                            canUpload={canUpload}
                            canDelete={canDelete}
                            onEdit={() => onEdit(doc)}
                            onReplace={() => onReplace(doc)}
                            onDelete={() => onDelete(doc)}
                            selectionMode={selectionMode}
                            selected={isSelected?.(doc.id) ?? false}
                            onSelectedChange={() => onToggle?.(doc.id)}
                        />
                    ))}
                </MobileRecordList>
            </div>

            <div className={DESKTOP_OPERATIONAL_TABLE_CLASS}>
                <OrganizationDataTable minWidth="min-w-[640px]" compact>
                    <TableHeader>
                        <DataTableHeaderRow>
                            {selectionMode ? (
                                <DataTableHead className="w-10 px-3">
                                    <Checkbox
                                        checked={
                                            allSelected
                                                ? true
                                                : partiallySelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={onToggleAll}
                                        aria-label="Select all documents"
                                    />
                                </DataTableHead>
                            ) : null}
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
                                selectionMode={selectionMode}
                                selected={isSelected?.(doc.id) ?? false}
                                onSelectedChange={() => onToggle?.(doc.id)}
                            />
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            </div>

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
