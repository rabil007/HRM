import { Head } from '@inertiajs/react';
import { CompanyDocumentsContent } from '@/features/organization/company-documents';
import type { CompanyDocumentsPageProps } from '@/features/organization/company-documents/types';

export default function CompanyDocuments(props: CompanyDocumentsPageProps) {
    return (
        <>
            <Head title={`${props.company.name} Documents`} />
            <CompanyDocumentsContent {...props} />
        </>
    );
}
