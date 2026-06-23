import { Head } from '@inertiajs/react';
import { SalaryAdjustmentsContent } from '@/features/payroll/adjustments/salary-adjustments-content';
import type {
    SalaryAdjustment,
    SalaryAdjustmentEmployeeOption,
    SalaryAdjustmentFilters,
    SalaryAdjustmentPeriodOption,
    SalaryAdjustmentPermissions,
} from '@/features/payroll/adjustments/types';
import type { PaginationMeta } from '@/types/pagination';

export default function SalaryAdjustments({
    adjustments,
    pagination,
    search,
    filters,
    employees,
    periods,
    type_options,
    status_options,
    can,
}: {
    adjustments: SalaryAdjustment[];
    pagination: PaginationMeta;
    search: string;
    filters: SalaryAdjustmentFilters;
    employees: SalaryAdjustmentEmployeeOption[];
    periods: SalaryAdjustmentPeriodOption[];
    type_options: Array<{ value: string; label: string }>;
    status_options: Array<{ value: string; label: string }>;
    can: SalaryAdjustmentPermissions;
}) {
    return (
        <>
            <Head title="Salary adjustments" />
            <SalaryAdjustmentsContent
                adjustments={adjustments}
                pagination={pagination}
                search={search}
                filters={filters}
                employees={employees}
                periods={periods}
                type_options={type_options}
                status_options={status_options}
                can={can}
            />
        </>
    );
}
