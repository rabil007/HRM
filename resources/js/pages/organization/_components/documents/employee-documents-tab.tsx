import { router, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeDocumentTableRow } from '@/features/organization/documents/employee-document-table-row';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import { buildDocumentShowUrl } from '@/features/organization/documents/shared/document-show-url';
import type {
    DocumentBrowseItem,
    DocumentProfileItem,
    DocumentTypeOption,
} from '@/features/organization/documents/shared/types';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { cn } from '@/lib/utils';
import { EditDocumentDialog } from '@/pages/organization/_components/documents/edit-document-dialog';
import { ReplaceDocumentDialog } from '@/pages/organization/_components/documents/replace-document-dialog';
import { UploadDocumentDialog } from '@/pages/organization/_components/documents/upload-dialog';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import type {
    EmployeeDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';
import documentRoutes, {
    employee as employeeDocumentsBrowse,
} from '@/routes/organization/documents';

const DOCUMENTS_RELOAD = {
    preserveScroll: true,
    only: ['documents'],
};

export type EmployeeDocumentsTabProps = {
    employee: Pick<EmployeeDetails, 'id' | 'name'> & {
        id: number | null;
    };
    documents: DocumentProfileItem[];
    document_types: DocumentTypeOption[];
    can: {
        documents_upload: boolean;
        documents_download: boolean;
        documents_delete: boolean;
    };
    ensureEmployee?: () => Promise<number>;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

function toBrowseItem(doc: DocumentProfileItem): DocumentBrowseItem {
    return {
        id: doc.id,
        document_name:
            doc.document_name ||
            doc.original_filename ||
            doc.title ||
            'Document',
        document_type:
            doc.document_type_label ||
            doc.document_type ||
            doc.type ||
            'Document',
        file_url: doc.file_url,
        uploaded_at: doc.uploaded_at,
        uploaded_by: doc.uploaded_by,
        mime_type: doc.mime_type,
        can_preview: doc.can_preview,
        status: doc.status,
        expiry_date: doc.expiry_date,
        issue_date: doc.issue_date,
        document_number: doc.document_number,
        size_bytes: doc.size_bytes,
        expiry_status: doc.expiry_status,
        remaining_days: doc.remaining_days,
        expiry_label: doc.expiry_label,
    };
}

export function EmployeeDocumentsTab({
    employee,
    documents,
    document_types,
    can,
    ensureEmployee,
    templateFields = null,
}: EmployeeDocumentsTabProps): ReactElement {
    const employeeId = employee.id;
    const hasEmployeeId = employeeId !== null && employeeId > 0;
    const selectionMode = can.documents_delete && hasEmployeeId;
    const [uploadOpen, setUploadOpen] = useState(false);
    const [editDoc, setEditDoc] = useState<DocumentProfileItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [replaceDoc, setReplaceDoc] = useState<DocumentProfileItem | null>(
        null,
    );

    const documentIds = useMemo(
        () => documents.map((doc) => doc.id),
        [documents],
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
    } = useBulkSelection(documentIds);

    return (
        <TabsContent value="documents" className="mt-6">
            {selectionMode && documents.length > 0 ? (
                <DocumentsBulkToolbar
                    count={selectedDocumentCount}
                    itemLabel="files"
                    onClear={clearDocumentSelection}
                    actions={
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            className="h-8 gap-1.5 text-xs"
                            disabled={isBulkDeleting}
                            onClick={() => setBulkDeleteOpen(true)}
                        >
                            Delete selected
                        </Button>
                    }
                />
            ) : null}

            <EmployeeRecordsPanel
                title="Documents"
                count={documents.length}
                isEmpty={documents.length === 0}
                emptyMessage="No documents uploaded."
                actions={
                    <div className="flex shrink-0 items-center gap-2">
                        {hasEmployeeId ? (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="h-8 gap-1.5 border-border bg-muted/50 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <Link
                                    href={employeeDocumentsBrowse.url({
                                        employee: employeeId,
                                    })}
                                >
                                    <FolderOpen className="h-3.5 w-3.5" />
                                    Open Documents
                                </Link>
                            </Button>
                        ) : null}
                        {can.documents_upload ? (
                            <Button
                                size="sm"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() => setUploadOpen(true)}
                            >
                                + Upload Document
                            </Button>
                        ) : null}
                    </div>
                }
            >
                <EmployeeRecordsTable className="min-w-[980px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {selectionMode ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'w-10 px-3 first:pl-3',
                                    )}
                                >
                                    <Checkbox
                                        checked={
                                            allDocumentsSelected
                                                ? true
                                                : documentsPartiallySelected
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={toggleAllDocuments}
                                        aria-label="Select all documents"
                                    />
                                </th>
                            ) : null}
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'min-w-[240px]',
                                )}
                            >
                                File
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'hidden md:table-cell',
                                )}
                            >
                                Document no.
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'hidden md:table-cell',
                                )}
                            >
                                Issue date
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'hidden lg:table-cell',
                                )}
                            >
                                Expiry
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'hidden lg:table-cell',
                                )}
                            >
                                Status
                            </th>
                            <th
                                className={cn(
                                    employeeRecordsTableThClass(),
                                    'hidden xl:table-cell',
                                )}
                            >
                                Uploaded
                            </th>
                            <EmployeeRecordsActionsHeader className="min-w-54" />
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((doc) => {
                            const browseDoc = toBrowseItem(doc);
                            const viewHref = hasEmployeeId
                                ? buildDocumentShowUrl(employeeId, doc.id, {
                                      from: 'profile',
                                  })
                                : '#';

                            return (
                                <EmployeeDocumentTableRow
                                    key={doc.id}
                                    doc={browseDoc}
                                    viewHref={viewHref}
                                    canDownload={can.documents_download}
                                    canUpload={can.documents_upload}
                                    canDelete={can.documents_delete}
                                    onEdit={
                                        hasEmployeeId
                                            ? () => setEditDoc(doc)
                                            : undefined
                                    }
                                    onReplace={
                                        hasEmployeeId
                                            ? () => setReplaceDoc(doc)
                                            : undefined
                                    }
                                    onDelete={
                                        hasEmployeeId
                                            ? () => setDeleteDocId(doc.id)
                                            : undefined
                                    }
                                    selectionMode={selectionMode}
                                    selected={isDocumentSelected(doc.id)}
                                    onSelectedChange={() =>
                                        toggleDocument(doc.id)
                                    }
                                />
                            );
                        })}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <UploadDocumentDialog
                open={uploadOpen}
                onOpenChange={setUploadOpen}
                employeeId={employeeId}
                employeeName={employee.name}
                documentTypes={document_types}
                ensureEmployee={ensureEmployee}
                templateFields={templateFields}
            />

            {hasEmployeeId ? (
                <EditDocumentDialog
                    key={editDoc?.id ?? 'closed'}
                    document={editDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setEditDoc(null)}
                    documentTypes={document_types}
                    templateFields={templateFields}
                />
            ) : null}

            {hasEmployeeId ? (
                <ReplaceDocumentDialog
                    document={replaceDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setReplaceDoc(null)}
                    templateFields={templateFields}
                />
            ) : null}

            <ConfirmDeleteDocumentDialog
                open={!!deleteDocId}
                onOpenChange={(open) => !open && setDeleteDocId(null)}
                onConfirm={() => {
                    if (!deleteDocId || !hasEmployeeId) {
                        return;
                    }

                    router.delete(
                        EmployeeDocumentController.destroy.url({
                            employee: employeeId,
                            document: deleteDocId,
                        }),
                        {
                            ...DOCUMENTS_RELOAD,
                            onSuccess: () => setDeleteDocId(null),
                        },
                    );
                }}
            />

            <ConfirmDeleteDocumentDialog
                open={bulkDeleteOpen}
                onOpenChange={setBulkDeleteOpen}
                title="Delete selected documents"
                description={
                    <>
                        Are you sure you want to delete {selectedDocumentCount}{' '}
                        selected{' '}
                        {selectedDocumentCount === 1 ? 'document' : 'documents'}
                        ? This action cannot be undone.
                    </>
                }
                confirmLabel={isBulkDeleting ? 'Deleting…' : 'Delete'}
                confirmDisabled={isBulkDeleting}
                onConfirm={() => {
                    if (
                        selectedDocumentIds.length === 0 ||
                        !hasEmployeeId
                    ) {
                        return;
                    }

                    setIsBulkDeleting(true);

                    router.delete(
                        documentRoutes.employee.files.bulkDestroy.url({
                            employee: employeeId,
                        }),
                        {
                            data: { document_ids: selectedDocumentIds },
                            ...DOCUMENTS_RELOAD,
                            onSuccess: () => {
                                clearDocumentSelection();
                                setBulkDeleteOpen(false);
                            },
                            onFinish: () => {
                                setIsBulkDeleting(false);
                            },
                        },
                    );
                }}
            />
        </TabsContent>
    );
}
