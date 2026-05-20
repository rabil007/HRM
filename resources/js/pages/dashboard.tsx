import { Head } from '@inertiajs/react';
import { DashboardContent } from '@/features/dashboard';

type DocumentCompliance = {
    total_documents: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
    uploaded_this_month: number;
};

export default function Dashboard({ document_compliance }: { document_compliance: DocumentCompliance }) {
    return (
        <>
            <Head title="Dashboard" />
            <DashboardContent documentCompliance={document_compliance} />
        </>
    );
}
