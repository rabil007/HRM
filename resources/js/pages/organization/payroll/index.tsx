import { Head } from '@inertiajs/react';
import { PayrollIndexContent } from '@/features/organization/payroll';
import type {
    PayrollCategoryOption,
    PayrollHubPermissions,
    PayrollPeriodListItem,
} from '@/features/organization/payroll/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayrollIndex({
    periods,
    pagination,
    payroll_categories,
    permissions,
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    payroll_categories: PayrollCategoryOption[];
    permissions: PayrollHubPermissions;
}) {
    return (
        <>
            <Head title="Payroll" />
            <PayrollIndexContent
                periods={periods}
                pagination={pagination}
                payroll_categories={payroll_categories}
                permissions={permissions}
            />
        </>
    );
}
