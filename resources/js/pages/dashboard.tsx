import { Head } from '@inertiajs/react';
import { DashboardContent } from '@/features/dashboard';
import type { DashboardProps } from '@/features/dashboard/dashboard-types';

export default function Dashboard(props: DashboardProps) {
    return (
        <>
            <Head title="Dashboard" />
            <DashboardContent {...props} />
        </>
    );
}
