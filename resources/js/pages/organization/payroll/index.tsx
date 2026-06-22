import { Head } from '@inertiajs/react';
import { PayrollIndexContent } from '@/features/organization/payroll';
import type { PayrollHubPermissions, PayrollPeriodListItem } from '@/features/organization/payroll/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayrollIndex({
    periods,
    pagination,
    permissions,
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    permissions: PayrollHubPermissions;
}) {
    return (
        <>
            <Head title="Payroll" />
            <PayrollIndexContent periods={periods} pagination={pagination} permissions={permissions} />
        </>
    );
}
