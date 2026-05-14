import { Head } from '@inertiajs/react';
import { DashboardContent } from '@/features/dashboard';

type DocumentCompliance = {
    valid: number;
    expiring_soon: number;
    expired: number;
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
