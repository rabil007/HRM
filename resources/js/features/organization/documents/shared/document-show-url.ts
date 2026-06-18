import type { DocumentShowBackContext } from '@/features/organization/documents/shared/types';
import { show as documentShow } from '@/routes/organization/documents/employee/files';

export function buildDocumentShowUrl(
    employeeId: number,
    documentId: number,
    back: DocumentShowBackContext,
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.from === 'index') {
        if (back.expiry && back.expiry !== 'all') {
            query.expiry = back.expiry;
        }

        if (back.search?.trim()) {
            query.search = back.search.trim();
        }

        if (back.page && back.page > 1) {
            query.page = String(back.page);
        }
    }

    return documentShow.url(
        { employee: employeeId, document: documentId },
        Object.keys(query).length > 0 ? { query } : undefined,
    );
}

export type { DocumentShowBackContext };
