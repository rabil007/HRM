import { Head } from '@inertiajs/react';
import { PayrollOverviewContent } from '@/features/payroll/overview/payroll-overview-content';
import type { PayrollOverviewProps } from '@/features/payroll/overview/payroll-overview-content';

export default function PayrollOverview(props: PayrollOverviewProps) {
    return (
        <>
            <Head title="Payroll Overview" />
            <PayrollOverviewContent {...props} />
        </>
    );
}
