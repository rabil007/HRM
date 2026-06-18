import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
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
    deleteDocId: number | null;
    onDeleteDocIdChange: (id: number | null) => void;
    templateFields?: Record<string, TemplateFieldConfig> | null;
    partialReloadKeys?: string[];
    deleteRedirectUrl?: string;
};

export function DocumentManagementDialogs({
    employeeId,
    editDoc,
    onEditDocChange,
    replaceDoc,
    onReplaceDocChange,
    deleteDocId,
    onDeleteDocIdChange,
    templateFields = null,
    partialReloadKeys = ['documents'],
    deleteRedirectUrl,
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
                            ...(deleteRedirectUrl
                                ? {}
                                : partialReloadKeys.length > 0
                                  ? { only: partialReloadKeys }
                                  : {}),
                            onSuccess: () => {
                                onDeleteDocIdChange(null);
                                if (deleteRedirectUrl) {
                                    router.visit(deleteRedirectUrl);
                                }
                            },
                        },
                    );
                }}
            />
        </>
    );
}
