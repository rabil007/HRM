import { router, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import { ManagementDocumentActions } from '@/features/organization/documents/shared/document-actions/management-actions';
import { DocumentExpiryStatusCell } from '@/features/organization/documents/shared/document-expiry-display';
import { DocumentPreviewDialog } from '@/features/organization/documents/shared/document-preview-dialog';
import { DocumentVersionsSheet } from '@/features/organization/documents/shared/document-versions-sheet';
import type { DocumentProfileItem, DocumentTypeOption } from '@/features/organization/documents/shared/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { EditDocumentDialog } from '@/pages/organization/_components/documents/edit-document-dialog';
import { ReplaceDocumentDialog } from '@/pages/organization/_components/documents/replace-document-dialog';
import { UploadDocumentDialog } from '@/pages/organization/_components/documents/upload-dialog';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsActionsTdClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';
import { employee as employeeDocumentsBrowse } from '@/routes/organization/documents';

const DOCUMENTS_RELOAD = {
    preserveScroll: true,
    only: ['documents'],
} as const;

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
};

export function EmployeeDocumentsTab({
    employee,
    documents,
    document_types,
    can,
    ensureEmployee,
}: EmployeeDocumentsTabProps): ReactElement {
    const employeeId = employee.id;
    const hasEmployeeId = employeeId !== null && employeeId > 0;
    const [uploadOpen, setUploadOpen] = useState(false);
    const [editDoc, setEditDoc] = useState<DocumentProfileItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [previewDoc, setPreviewDoc] = useState<DocumentProfileItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentProfileItem | null>(null);
    const [versionDoc, setVersionDoc] = useState<DocumentProfileItem | null>(null);

    return (
        <TabsContent value="documents" className="mt-6">
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
                            <Button size="sm" className="h-8 gap-1.5 text-xs" onClick={() => setUploadOpen(true)}>
                                + Upload Document
                            </Button>
                        ) : null}
                    </div>
                }
            >
                <EmployeeRecordsTable className="min-w-[1020px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Type</th>
                            <th className={employeeRecordsTableThClass()}>Title</th>
                            <th className={employeeRecordsTableThClass()}>Number</th>
                            <th className={employeeRecordsTableThClass()}>Issue</th>
                            <th className={employeeRecordsTableThClass()}>Expiry</th>
                            <th className={employeeRecordsTableThClass()}>Status</th>
                            <th className={employeeRecordsTableThClass()}>Uploaded by</th>
                            <EmployeeRecordsActionsHeader className="min-w-[13.5rem]" />
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((doc) => (
                            <tr key={doc.id} className={employeeRecordsTableRowClass()}>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                    {doc.document_type_label ??
                                        document_types.find(
                                            (t) =>
                                                String(t.id) ===
                                                String(doc.document_type_id ?? doc.document_type),
                                        )?.title ??
                                        doc.document_type ??
                                        doc.type ??
                                        '—'}
                                    {doc.current_version && doc.current_version > 1 ? (
                                        <span className="ml-1 text-[10px] text-muted-foreground">
                                            v{doc.current_version}
                                        </span>
                                    ) : null}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'font-medium text-foreground')}>
                                    {doc.title || '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'font-mono text-xs text-muted-foreground')}>
                                    {doc.document_number || '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                    {formatDisplayDate(doc.issue_date)}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                    {formatDisplayDate(doc.expiry_date)}
                                </td>
                                <td className={employeeRecordsTableTdClass()}>
                                    <DocumentExpiryStatusCell
                                        status={doc.expiry_status}
                                        className="text-xs capitalize"
                                    />
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                    {doc.uploaded_by || '—'}
                                </td>
                                <td className={employeeRecordsActionsTdClass('min-w-[13.5rem]')}>
                                    <ManagementDocumentActions
                                        documentId={doc.id}
                                        canPreview={!!doc.can_preview}
                                        fileUrl={doc.file_url}
                                        onPreview={() => setPreviewDoc(doc)}
                                        showDownload={can.documents_download}
                                        showVersions={can.documents_upload}
                                        onVersions={() => setVersionDoc(doc)}
                                        showReplace={can.documents_upload}
                                        onReplace={() => setReplaceDoc(doc)}
                                        showEdit={can.documents_upload}
                                        onEdit={() => setEditDoc(doc)}
                                        showDelete={can.documents_delete}
                                        onDelete={() => setDeleteDocId(doc.id)}
                                    />
                                </td>
                            </tr>
                        ))}
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
            />

            {hasEmployeeId ? (
                <EditDocumentDialog
                    key={editDoc?.id ?? 'closed'}
                    document={editDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setEditDoc(null)}
                />
            ) : null}

            {hasEmployeeId ? (
                <ReplaceDocumentDialog
                    document={replaceDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setReplaceDoc(null)}
                />
            ) : null}

            {hasEmployeeId ? (
                <DocumentVersionsSheet
                    open={!!versionDoc}
                    onOpenChange={(open) => !open && setVersionDoc(null)}
                    employeeId={employeeId}
                    documentId={versionDoc?.id ?? null}
                    documentTitle={
                        versionDoc?.title ?? versionDoc?.document_type_label ?? null
                    }
                    showDownload={can.documents_download}
                />
            ) : null}

            <DocumentPreviewDialog
                document={previewDoc}
                onOpenChange={(open) => !open && setPreviewDoc(null)}
            />

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
        </TabsContent>
    );
}
