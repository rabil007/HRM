import { Head } from '@inertiajs/react';
import { PayrollIndexContent } from '@/features/payroll';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
import type {
    PayrollCategoryOption,
    PayrollHubFilters,
    PayrollHubPermissions,
    PayrollHubSummary,
    PayrollPeriodListItem,
    PayrollPeriodStatusOption,
} from '@/features/payroll/types';
import type { PaginationMeta } from '@/types/pagination';

export default function PayrollIndex({
    periods,
    pagination,
    search,
    filters,
    summary,
    payroll_categories,
    payroll_period_statuses,
    company_visa_types,
    permissions,
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollHubFilters;
    summary: PayrollHubSummary;
    payroll_categories: PayrollCategoryOption[];
    payroll_period_statuses: PayrollPeriodStatusOption[];
    company_visa_types: CompanyVisaTypeOption[];
    permissions: PayrollHubPermissions;
}) {
    return (
        <>
            <Head title="Payroll" />
            <PayrollIndexContent
                periods={periods}
                pagination={pagination}
                search={search}
                filters={filters}
                summary={summary}
                payroll_categories={payroll_categories}
                payroll_period_statuses={payroll_period_statuses}
                company_visa_types={company_visa_types}
                permissions={permissions}
            />
        </>
    );
}
