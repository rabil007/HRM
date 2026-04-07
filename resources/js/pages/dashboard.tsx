import { Head } from '@inertiajs/react';
import { DashboardContent } from '@/features/dashboard';

export default function Dashboard() {
    return (
        <>
            <Head title="Dashboard" />
            <DashboardContent />
        </>
    );
}
