import { Head } from '@inertiajs/react';
import { DocumentRequestsContent } from '@/features/organization/documents/workflow/document-requests-content';
import type { DocumentRequestsIndexProps } from '@/features/organization/documents/workflow/types';

export default function DocumentRequestsIndex(
    props: DocumentRequestsIndexProps,
) {
    return (
        <>
            <Head title="Requests" />
            <DocumentRequestsContent {...props} />
        </>
    );
}
