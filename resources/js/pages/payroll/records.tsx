import { Head } from '@inertiajs/react';
import { PayrollRecordsContent } from '@/features/payroll/records/payroll-records-content';
import type { PayrollRecordsFilters } from '@/features/payroll/records/types';
import type { PayrollCategoryOption } from '@/features/payroll/types';
import type { PaginationMeta } from '@/types/pagination';
import type { PayrollRecordIndexItem } from '@/features/payroll/records/types';

export default function PayrollRecords({
    records,
    pagination,
    search,
    filters,
    payroll_categories,
    status_options,
    counts,
}: {
    records: PayrollRecordIndexItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollRecordsFilters;
    payroll_categories: PayrollCategoryOption[];
    status_options: Array<{ value: string; label: string }>;
    counts: { all: number; draft: number; approved: number; paid: number };
}) {
    return (
        <>
            <Head title="Payroll records" />
            <PayrollRecordsContent
                records={records}
                pagination={pagination}
                search={search}
                filters={filters}
                payroll_categories={payroll_categories}
                status_options={status_options}
                counts={counts}
            />
        </>
    );
}
