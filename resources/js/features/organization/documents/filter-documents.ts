import { formatDisplayDate } from '@/lib/format-date';
import type { DocumentBrowseItem } from './types';

export function matchesFileSearch(doc: DocumentBrowseItem, query: string): boolean {
    const haystack = [
        doc.document_name,
        doc.document_type,
        doc.document_number,
        formatDisplayDate(doc.uploaded_at),
        formatDisplayDate(doc.issue_date),
        formatDisplayDate(doc.expiry_date),
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
): DocumentBrowseItem[] {
    const query = search.trim().toLowerCase();

    if (query === '') {
        return documents;
    }

    return documents.filter((doc) => matchesFileSearch(doc, query));
}
