import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import { DocumentVersionsSheet } from '@/features/organization/documents/shared/document-versions-sheet';
import type { DocumentProfileItem } from '@/features/organization/documents/shared/types';
import { EditDocumentDialog } from '@/pages/organization/_components/documents/edit-document-dialog';
import { ReplaceDocumentDialog } from '@/pages/organization/_components/documents/replace-document-dialog';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

type DocumentManagementDialogsProps = {
    employeeId: number;
    editDoc: DocumentProfileItem | null;
    onEditDocChange: (doc: DocumentProfileItem | null) => void;
    replaceDoc: DocumentProfileItem | null;
    onReplaceDocChange: (doc: DocumentProfileItem | null) => void;
    versionDoc: DocumentProfileItem | null;
    onVersionDocChange: (doc: DocumentProfileItem | null) => void;
    deleteDocId: number | null;
    onDeleteDocIdChange: (id: number | null) => void;
    canDownload: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
    partialReloadKeys?: string[];
};

export function DocumentManagementDialogs({
    employeeId,
    editDoc,
    onEditDocChange,
    replaceDoc,
    onReplaceDocChange,
    versionDoc,
    onVersionDocChange,
    deleteDocId,
    onDeleteDocIdChange,
    canDownload,
    templateFields = null,
    partialReloadKeys = ['documents'],
}: DocumentManagementDialogsProps): ReactElement {
    return (
        <>
            <EditDocumentDialog
                key={editDoc?.id ?? 'closed'}
                document={editDoc}
                employeeId={employeeId}
                onOpenChange={(open) => !open && onEditDocChange(null)}
                templateFields={templateFields}
                partialReloadKeys={partialReloadKeys}
            />

            <ReplaceDocumentDialog
                document={replaceDoc}
                employeeId={employeeId}
                onOpenChange={(open) => !open && onReplaceDocChange(null)}
                partialReloadKeys={partialReloadKeys}
            />

            <DocumentVersionsSheet
                open={!!versionDoc}
                onOpenChange={(open) => !open && onVersionDocChange(null)}
                employeeId={employeeId}
                documentId={versionDoc?.id ?? null}
                documentTitle={
                    versionDoc?.title ?? versionDoc?.document_type_label ?? null
                }
                showDownload={canDownload}
            />

            <ConfirmDeleteDocumentDialog
                open={!!deleteDocId}
                onOpenChange={(open) => !open && onDeleteDocIdChange(null)}
                onConfirm={() => {
                    if (!deleteDocId) {
                        return;
                    }

                    router.delete(
                        EmployeeDocumentController.destroy.url({
                            employee: employeeId,
                            document: deleteDocId,
                        }),
                        {
                            preserveScroll: true,
                            ...(partialReloadKeys.length > 0
                                ? { only: partialReloadKeys }
                                : {}),
                            onSuccess: () => onDeleteDocIdChange(null),
                        },
                    );
                }}
            />
        </>
    );
}
