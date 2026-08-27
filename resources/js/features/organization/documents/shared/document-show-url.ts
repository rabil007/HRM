import { documentShowBackQuery } from '@/features/organization/documents/lib/document-show-back-query';
import type { DocumentShowBackContext } from '@/features/organization/documents/shared/types';
import { show as documentShow } from '@/routes/organization/documents/employee/files';

export function buildDocumentShowUrl(
    employeeId: number,
    documentId: number,
    back: DocumentShowBackContext,
): string {
    const query = documentShowBackQuery(back);

    return documentShow.url(
        { employee: employeeId, document: documentId },
        Object.keys(query).length > 0 ? { query } : undefined,
    );
}

export { documentShowBackQuery };
export type { DocumentShowBackContext };
