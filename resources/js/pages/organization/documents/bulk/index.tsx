import { Head } from '@inertiajs/react';
import { BulkDocumentsContent } from '@/features/organization/documents/bulk/bulk-documents-content';
import type { BulkDocumentsPageProps } from '@/features/organization/documents/bulk/types';

export default function BulkDocumentsIndex(props: BulkDocumentsPageProps) {
    return (
        <>
            <Head title="Bulk generate" />
            <BulkDocumentsContent {...props} />
        </>
    );
}
