export type DocumentsIndexSearchMode =
    | 'browse'
    | 'documents-only'
    | 'employees-only'
    | 'tabbed'
    | 'empty';

export type DocumentsIndexSearchTab = 'all' | 'employees' | 'documents';

export function resolveDocumentsIndexSearchMode(
    hasSearch: boolean,
    employeeCount: number,
    documentTotal: number,
): DocumentsIndexSearchMode {
    if (!hasSearch) {
        return 'browse';
    }

    const hasEmployees = employeeCount > 0;
    const hasDocuments = documentTotal > 0;

    if (!hasEmployees && !hasDocuments) {
        return 'empty';
    }

    if (hasEmployees && hasDocuments) {
        return 'tabbed';
    }

    if (hasDocuments) {
        return 'documents-only';
    }

    return 'employees-only';
}
