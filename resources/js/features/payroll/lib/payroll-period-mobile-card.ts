import type { PayrollPeriodListItem } from '../types';

export type PayrollPeriodMobileCardModel = {
    title: string;
    categoryLabel: string;
    dateRange: string;
    workflowLine: string;
    status: string;
    statusLabel: string;
    showOpen: boolean;
    exposesSalary: boolean;
};

export function payrollPeriodMobileCardModel(
    period: PayrollPeriodListItem,
    canOpen: boolean,
): PayrollPeriodMobileCardModel {
    const employeeCount = `${period.employee_count} ${period.payroll_category_label.toLowerCase()} employee${period.employee_count === 1 ? '' : 's'}`;
    const workflowLine = period.supports_timesheets
        ? (period.timesheets_progress_label ?? employeeCount)
        : 'Leave-based payroll';

    return {
        title: period.name,
        categoryLabel: period.payroll_category_label,
        dateRange: `${period.start_date} – ${period.end_date}`,
        workflowLine,
        status: period.status,
        statusLabel: period.status_label,
        showOpen: canOpen,
        exposesSalary: false,
    };
}
