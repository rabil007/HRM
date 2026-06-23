import type { PaginationMeta } from '@/types/pagination';

export type PayrollCategory = 'office' | 'crew';

export type PayrollCategoryOption = {
    value: PayrollCategory;
    label: string;
};

export type PayrollPeriodStatus =
    | 'draft'
    | 'processing'
    | 'approved'
    | 'paid'
    | 'cancelled';

export type PayrollPeriodStatusOption = {
    value: PayrollPeriodStatus;
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
    can_generate_crew_payroll: boolean;
    can_revert_to_draft: boolean;
    can_approve: boolean;
    can_mark_paid: boolean;
    can_cancel: boolean;
    payroll_records_count: number;
    approved_at: string | null;
    approver: { id: number; name: string } | null;
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
    status: PayrollPeriodStatus | '';
    date_from: string;
    date_to: string;
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
    generate_payroll: boolean;
    revert_to_draft: boolean;
    approve: boolean;
    mark_paid: boolean;
    cancel: boolean;
};

export type PayrollRecordListItem = {
    id: number;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    };
    standby_days: number | null;
    onsite_days: number | null;
    standby_pay: string;
    onsite_pay: string;
    site_allowance: string;
    supplementary_allowance: string;
    overtime_pay: string;
    additional_amount: string;
    deduction_amount: string;
    gross_salary: string;
    net_salary: string;
    status: string;
};

export type CrewPayrollGenerationSummary = {
    generated_count: number;
    skipped_count: number;
    skipped_employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
    }>;
    errors: Array<{
        employee_id: number;
        message: string;
    }>;
};

export type PayrollShowProps = {
    period: PayrollPeriod;
    rows: CrewPayrollRow[];
    pagination: PaginationMeta;
    board_summary: PayrollBoardSummary;
    payroll_records: PayrollRecordListItem[];
    payroll_records_pagination: PaginationMeta | null;
    tab: 'timesheets' | 'payroll';
    generation_summary: CrewPayrollGenerationSummary | null;
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
