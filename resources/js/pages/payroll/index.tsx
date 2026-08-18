import { Head } from '@inertiajs/react';
import { PayrollIndexContent } from '@/features/payroll';
import type {
    PayrollCategoryOption,
    PayrollHubFilters,
    PayrollHubPermissions,
    PayrollHubSummary,
    PayrollPeriodListItem,
    PayrollPeriodStatusOption,
} from '@/features/payroll/types';
import type { SavedView } from '@/lib/saved-views';
import type { PaginationMeta } from '@/types/pagination';

export default function PayrollIndex({
    periods,
    pagination,
    search,
    filters,
    summary,
    payroll_categories,
    payroll_period_statuses,
    permissions,
    saved_views = [],
}: {
    periods: PayrollPeriodListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: PayrollHubFilters;
    summary: PayrollHubSummary;
    payroll_categories: PayrollCategoryOption[];
    payroll_period_statuses: PayrollPeriodStatusOption[];
    permissions: PayrollHubPermissions;
    saved_views?: SavedView[];
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
                permissions={permissions}
                saved_views={saved_views}
            />
        </>
    );
}
