import { Head } from '@inertiajs/react';
import { PayslipsContent } from '@/features/payroll/payslips/payslips-content';
import type { PayslipListItem, PayslipsFilters } from '@/features/payroll/payslips/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayslipsPage({
    records,
    pagination,
    search,
    filters,
    permissions,
}: {
    records: PayslipListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayslipsFilters;
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
                permissions={permissions}
            />
        </>
    );
}
