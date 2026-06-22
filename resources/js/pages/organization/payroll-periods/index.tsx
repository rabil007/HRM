import { Head } from '@inertiajs/react';
import { PayrollPeriodsContent } from '@/features/organization/payroll-periods';
import type { PayrollPeriod, PayrollPeriodPermissions } from '@/features/organization/payroll-periods/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayrollPeriodsIndex({
    periods,
    pagination,
    permissions,
}: {
    periods: PayrollPeriod[];
    pagination: PaginationMeta;
    permissions: PayrollPeriodPermissions;
}) {
    return (
        <>
            <Head title="Payroll Periods" />
            <PayrollPeriodsContent periods={periods} pagination={pagination} permissions={permissions} />
        </>
    );
}
