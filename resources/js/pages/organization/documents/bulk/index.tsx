import { Head } from '@inertiajs/react';
import { BulkDocumentsContent } from '@/features/organization/documents/bulk/bulk-documents-content';
import type { BulkDocumentsPageProps } from '@/features/organization/documents/bulk/types';

export default function BulkDocumentsIndex(props: BulkDocumentsPageProps) {
    const title =
        props.section === 'requests'
            ? 'Requests'
            : props.section === 'activity'
              ? 'Activity'
              : props.section === 'generate'
                ? 'Generate & Send'
                : 'Generate & Send';

    return (
        <>
            <Head title={title} />
            <BulkDocumentsContent {...props} />
        </>
    );
}
