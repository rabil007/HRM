import { Head } from '@inertiajs/react';
import { PayslipsContent } from '@/features/payroll/payslips/payslips-content';
import type { PayslipListItem, PayslipsFilters } from '@/features/payroll/payslips/types';
import type { PayrollCategoryOption } from '@/features/payroll/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayslipsPage({
    records,
    pagination,
    search,
    filters,
    payroll_categories,
    status_options,
    permissions,
}: {
    records: PayslipListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayslipsFilters;
    payroll_categories: PayrollCategoryOption[];
    status_options: Array<{ value: string; label: string }>;
    permissions: { generate: boolean; email: boolean };
}) {
    return (
        <>
            <Head title="Payslips" />
            <PayslipsContent
                records={records}
                pagination={pagination}
                search={search}
                filters={filters}
                payroll_categories={payroll_categories}
                status_options={status_options}
                permissions={permissions}
            />
        </>
    );
}
