import { router, Link } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import { DocumentListRowActions } from '@/features/organization/documents/shared/document-actions/document-list-row-actions';
import { buildDocumentShowUrl } from '@/features/organization/documents/shared/document-show-url';
import { DocumentExpiryStatusCell } from '@/features/organization/documents/shared/document-expiry-display';
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
import { createTemplateFieldVisibility } from '@/pages/organization/_lib/template-field-visibility';
import type {
    EmployeeDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';
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
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeDocumentsTab({
    employee,
    documents,
    document_types,
    can,
    ensureEmployee,
    templateFields = null,
}: EmployeeDocumentsTabProps): ReactElement {
    const showField = useMemo(
        () => createTemplateFieldVisibility(templateFields),
        [templateFields],
    );

    const employeeId = employee.id;
    const hasEmployeeId = employeeId !== null && employeeId > 0;
    const [uploadOpen, setUploadOpen] = useState(false);
    const [editDoc, setEditDoc] = useState<DocumentProfileItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentProfileItem | null>(null);

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
                            {showField('document_type_id') ? (
                                <th className={employeeRecordsTableThClass()}>Type</th>
                            ) : null}
                            {showField('title') ? (
                                <th className={employeeRecordsTableThClass()}>Title</th>
                            ) : null}
                            {showField('document_number') ? (
                                <th className={employeeRecordsTableThClass()}>Number</th>
                            ) : null}
                            {showField('issue_date') ? (
                                <th className={employeeRecordsTableThClass()}>Issue</th>
                            ) : null}
                            {showField('expiry_date') ? (
                                <th className={employeeRecordsTableThClass()}>Expiry</th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>Status</th>
                            <th className={employeeRecordsTableThClass()}>Uploaded by</th>
                            <EmployeeRecordsActionsHeader className="min-w-[13.5rem]" />
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((doc) => (
                            <tr
                                key={doc.id}
                                className={cn(
                                    employeeRecordsTableRowClass(),
                                    hasEmployeeId && 'cursor-pointer',
                                )}
                                onClick={() => {
                                    if (!hasEmployeeId) {
                                        return;
                                    }

                                    router.visit(
                                        buildDocumentShowUrl(employeeId, doc.id, {
                                            from: 'profile',
                                        }),
                                    );
                                }}
                            >
                                {showField('document_type_id') ? (
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
                                ) : null}
                                {showField('title') ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'font-medium text-foreground')}>
                                        {doc.title || '—'}
                                    </td>
                                ) : null}
                                {showField('document_number') ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'font-mono text-xs text-muted-foreground')}>
                                        {doc.document_number || '—'}
                                    </td>
                                ) : null}
                                {showField('issue_date') ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                        {formatDisplayDate(doc.issue_date)}
                                    </td>
                                ) : null}
                                {showField('expiry_date') ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-xs text-muted-foreground')}>
                                        {formatDisplayDate(doc.expiry_date)}
                                    </td>
                                ) : null}
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
                                    {hasEmployeeId ? (
                                        <DocumentListRowActions
                                            documentId={doc.id}
                                            fileUrl={doc.file_url}
                                            viewHref={buildDocumentShowUrl(employeeId, doc.id, {
                                                from: 'profile',
                                            })}
                                            showDownload={can.documents_download}
                                            showReplace={can.documents_upload}
                                            onReplace={() => setReplaceDoc(doc)}
                                            showEdit={can.documents_upload}
                                            onEdit={() => setEditDoc(doc)}
                                            showDelete={can.documents_delete}
                                            onDelete={() => setDeleteDocId(doc.id)}
                                        />
                                    ) : null}
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
                templateFields={templateFields}
            />

            {hasEmployeeId ? (
                <EditDocumentDialog
                    key={editDoc?.id ?? 'closed'}
                    document={editDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setEditDoc(null)}
                    templateFields={templateFields}
                />
            ) : null}

            {hasEmployeeId ? (
                <ReplaceDocumentDialog
                    document={replaceDoc}
                    employeeId={employeeId}
                    onOpenChange={(open) => !open && setReplaceDoc(null)}
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
        </TabsContent>
    );
}
