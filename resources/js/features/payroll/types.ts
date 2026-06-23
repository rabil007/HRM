import type { PaginationMeta } from '@/types/pagination';

export type PayrollCategory = 'office' | 'crew';

export type PayrollCategoryOption = {
    value: PayrollCategory;
    label: string;
};

export type PayrollPeriod = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    payment_date: string;
    payroll_category: PayrollCategory;
    payroll_category_label: string;
    supports_timesheets: boolean;
    status: string;
    status_label: string;
    notes: string | null;
    is_editable: boolean;
    created_at: string | null;
};

export type PayrollPeriodListItem = PayrollPeriod & {
    run_label: string;
    employee_count: number;
    timesheets_filled_count: number;
    timesheets_progress_label: string | null;
};

export type PayrollPeriodFormData = {
    name: string;
    payroll_category: PayrollCategory;
    start_date: string;
    end_date: string;
    payment_date: string;
    notes: string;
};

export type PayrollHubPermissions = {
    create_period: boolean;
    view_crew_timesheets: boolean;
};

export type PayrollHubFilters = {
    category: PayrollCategory | '';
};

export type PayrollHubSummary = {
    total_periods: number;
    draft_periods: number;
    crew_periods: number;
    office_periods: number;
    incomplete_crew_runs: number;
};

export type PayrollBoardSummary = {
    employee_count: number;
    filled_count: number;
    progress_percent: number;
};

export type CrewTimesheet = {
    id: number;
    period_id: number;
    employee_id: number;
    standby_from: string | null;
    standby_to: string | null;
    standby_days: string | null;
    onsite_from: string | null;
    onsite_to: string | null;
    onsite_days: string | null;
    overtime_amount: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string | null;
};

export type CrewPayrollRow = {
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    };
    period_id: number;
    timesheet: CrewTimesheet | null;
    is_filled: boolean;
};

export type CrewTimesheetFormData = {
    period_id: number;
    employee_id: number;
    standby_from: string;
    standby_to: string;
    standby_days: string;
    onsite_from: string;
    onsite_to: string;
    onsite_days: string;
    overtime_amount: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string;
};

export type CrewPayrollPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
};

export type PayrollShowProps = {
    period: PayrollPeriod;
    rows: CrewPayrollRow[];
    pagination: PaginationMeta;
    board_summary: PayrollBoardSummary;
    search: string;
    permissions: CrewPayrollPermissions;
    timesheet_draft: CrewTimesheetFormData | null;
};

function formatAmount(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatTimesheetAmount(value: string | null | undefined): string {
    return formatAmount(value);
}

export function formatTimesheetDays(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

export function getPeriodProgressPercent(period: PayrollPeriodListItem): number {
    if (!period.supports_timesheets || period.employee_count === 0) {
        return 0;
    }

    return Math.round((period.timesheets_filled_count / period.employee_count) * 100);
}
