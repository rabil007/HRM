import type { ExpiryFilter } from './document-expiry';
import type { DocumentBrowseItem } from './types';

export function matchesExpiryFilter(doc: DocumentBrowseItem, filter: ExpiryFilter): boolean {
    if (filter === 'all') {
        return true;
    }

    if (! doc.expiry_status) {
        return false;
    }

    if (filter === 'expired') {
        return doc.expiry_status === 'expired';
    }

    if (filter === 'expiring_7') {
        return doc.expiry_status === 'expiring_7';
    }

    if (filter === 'expiring_15') {
        return doc.expiry_status === 'expiring_7' || doc.expiry_status === 'expiring_15';
    }

    return (
        doc.expiry_status === 'expiring_7' ||
        doc.expiry_status === 'expiring_15' ||
        doc.expiry_status === 'expiring_30'
    );
}

export function filterDocumentsByExpiry(
    documents: DocumentBrowseItem[],
    filter: ExpiryFilter,
): DocumentBrowseItem[] {
    if (filter === 'all') {
        return documents;
    }

    return documents.filter((doc) => matchesExpiryFilter(doc, filter));
}
