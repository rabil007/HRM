import { toCompanyDateLocal } from '../../../lib/company-timezone.ts';
import { formatDisplayDate } from '../../../lib/format-date-core.ts';
import type { DocumentBrowseItem } from './types';

export type DocumentSearchDisplayOptions = {
    timeZone: string;
    dateFormat?: string | null;
};

export function matchesFileSearch(
    doc: DocumentBrowseItem,
    query: string,
    options: DocumentSearchDisplayOptions,
): boolean {
    const uploadedAtLocal = doc.uploaded_at
        ? toCompanyDateLocal(doc.uploaded_at, options.timeZone)
        : '';

    const haystack = [
        doc.document_name,
        doc.document_type,
        doc.document_number,
        uploadedAtLocal
            ? formatDisplayDate(uploadedAtLocal, options.dateFormat)
            : '',
        formatDisplayDate(doc.issue_date, options.dateFormat),
        formatDisplayDate(doc.expiry_date, options.dateFormat),
        doc.expiry_label,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(query);
}

export function filterDocuments(
    documents: DocumentBrowseItem[],
    search: string,
    options: DocumentSearchDisplayOptions,
): DocumentBrowseItem[] {
    const query = search.trim().toLowerCase();

    if (query === '') {
        return documents;
    }

    return documents.filter((doc) => matchesFileSearch(doc, query, options));
}
