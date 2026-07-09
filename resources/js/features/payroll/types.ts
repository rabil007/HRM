import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type PayrollBoardEmployeeGroup =
    | ''
    | 'with_bank_account'
    | 'cash_payment'
    | 'missing_bank_account';

export type CrewSalaryStructureView = 'daily' | 'monthly';

export type PayrollShowFilters = {
    department_id: string;
    position_id: string;
    employee_group: PayrollBoardEmployeeGroup;
    crew_salary_structure: CrewSalaryStructureView;
};

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
    can_generate_payroll: boolean;
    can_revert_to_draft: boolean;
    can_revert_to_approved: boolean;
    can_revert_to_processing: boolean;
    can_approve: boolean;
    can_mark_paid: boolean;
    can_cancel: boolean;
    payroll_records_count: number;
    excluded_employee_ids: number[];
    approved_at: string | null;
    approver: { id: number; name: string } | null;
    has_payment_proof?: boolean;
    payment_proof_url?: string | null;
    payment_proofs?: Array<{ id: number; name: string; url: string }>;
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
    crew_periods: number;
    office_periods: number;
    incomplete_crew_runs: number;
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
    overtime_hours: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string | null;
};

export type LeaveTypeColumn = {
    id: number;
    name: string;
    code: string;
    color: string | null;
};

export type OfficeLeaveUsage = {
    leave_type_id: number;
    code: string;
    name: string;
    color: string | null;
    days: number;
};

export type OfficePrimaryAccount = {
    bank_name: string | null;
    account_name: string | null;
    iban: string | null;
};

export type PayrollEmployeeIdentity = {
    id: number;
    name: string;
    employee_no: string | null;
    image?: string | null;
    department?: {
        id: number;
        name: string | null;
        parent?: { id: number; name: string | null } | null;
    } | null;
    position?: {
        id: number;
        title: string | null;
    } | null;
};

export type CrewPayrollRow = {
    employee: PayrollEmployeeIdentity;
    period_id: number;
    timesheet: CrewTimesheet | null;
    is_filled: boolean;
    salary_structure?: 'daily' | 'monthly';
    leave_usage?: OfficeLeaveUsage[];
    total_leave_days?: number;
    primary_account?: OfficePrimaryAccount | null;
    salary_payment_method?: string;
    salary_payment_method_label?: string;
    contract?: {
        basic_salary: string | null;
        housing_allowance?: string | null;
        transport_allowance?: string | null;
        other_allowances?: string | null;
        supplementary_allowance?: string | null;
        site_allowance?: string | null;
    } | null;
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
    overtime_hours: string;
    additional_amount: string;
    deduction_amount: string;
    remarks: string;
};

export type CrewTimesheetDraft = {
    standby_from: string;
    standby_to: string;
    onsite_from: string;
    onsite_to: string;
    overtime_hours: string;
};

export function buildCrewTimesheetDraft(
    timesheet: CrewTimesheet | null | undefined,
): CrewTimesheetDraft {
    return {
        standby_from: timesheet?.standby_from ?? '',
        standby_to: timesheet?.standby_to ?? '',
        onsite_from: timesheet?.onsite_from ?? '',
        onsite_to: timesheet?.onsite_to ?? '',
        overtime_hours: timesheet?.overtime_hours ?? '',
    };
}

export type CrewPayrollPermissions = {
    create: boolean;
    update: boolean;
    import_timesheets: boolean;
    generate_payroll: boolean;
    revert_to_draft: boolean;
    revert_to_approved: boolean;
    revert_to_processing: boolean;
    approve: boolean;
    mark_paid: boolean;
    cancel: boolean;
    salary_inputs_create: boolean;
    salary_inputs_update: boolean;
    salary_inputs_delete: boolean;
    recalculate_payroll: boolean;
    wps_export: boolean;
    export_payroll: boolean;
    payslips_generate: boolean;
    payslips_email: boolean;
};

export type PayslipSummary = {
    total: number;
    generated: number;
    pending: number;
};

export type WpsPreview = {
    period: { id: number; name: string };
    eligible_count: number;
    skipped: Array<{
        record_id: number;
        employee_id: number | null;
        employee_name: string;
        employee_no: string | null;
        reason: string;
    }>;
    company: {
        wps_mol_uid: string | null;
        wps_agent_code: string | null;
        wps_employer_iban: string | null;
    };
};

export type PayrollRecordDeliveryFields = {
    has_payslip: boolean;
    wps_status: string | null;
    wps_status_label: string | null;
    salary_payment_method: string;
    salary_payment_method_label: string;
};

export type CrewPayrollDailyRates = {
    basic_daily: string;
    site_allowance_daily: string;
    supplementary_allowance_daily: string;
};

export type CrewOvertimeBreakdown = {
    hours: string;
    period_days?: string | number | null;
    daily_onsite_rate?: string;
    monthly_salary: string;
    hour_rate: string;
    overtime_hourly_rate: string;
    overtime_pay: string;
};

export type CrewPayrollRecordListItem = PayrollRecordDeliveryFields & {
    id: number;
    payroll_category: 'crew';
    employee: PayrollEmployeeIdentity;
    salary_structure?: 'daily' | 'monthly';
    basic_salary: string;
    standby_days?: number | null;
    onsite_days?: number | null;
    standby_pay?: string;
    onsite_pay?: string;
    site_allowance?: string;
    supplementary_allowance?: string;
    housing_allowance?: string;
    transport_allowance?: string;
    other_allowances?: string;
    rates?: CrewPayrollDailyRates;
    overtime_hours?: string;
    overtime?: CrewOvertimeBreakdown;
    overtime_pay: string;
    additional_amount: string;
    deduction_amount: string;
    gross_salary: string;
    net_salary: string;
    working_days?: number;
    present_days?: number;
    absent_days?: number;
    unpaid_leave_deduction?: string;
    primary_account: OfficePrimaryAccount | null;
    salary_inputs_count: number;
    status: string;
};

export type OfficePayrollRecordListItem = PayrollRecordDeliveryFields & {
    id: number;
    payroll_category: 'office';
    employee: PayrollEmployeeIdentity;
    basic_salary: string;
    housing_allowance?: string;
    transport_allowance?: string;
    other_allowances?: string;
    primary_account: OfficePrimaryAccount | null;
    overtime_pay: string;
    additional_amount: string;
    gross_salary: string;
    net_salary: string;
    salary_inputs_count: number;
    working_days?: number | null;
    present_days?: number | null;
    absent_days?: number | null;
    unpaid_leave_deduction?: string | null;
    status: string;
};

export type SalaryInputTypeOption = {
    value: number;
    label: string;
    code: string;
    is_addition: boolean;
};

export type SalaryInput = {
    id: number;
    employee_id: number;
    period_id: number;
    salary_input_type_id: number;
    type: string | null;
    type_label: string | null;
    is_addition: boolean;
    amount: string;
    notes: string | null;
};

export type SalaryInputFormData = {
    employee_id: number;
    salary_input_type_id: number;
    amount: string;
    notes: string;
};

export type PayrollRecordListItem =
    | CrewPayrollRecordListItem
    | OfficePayrollRecordListItem;

export type PayrollGenerationSummary = {
    generated_count: number;
    skipped_count: number;
    skipped_employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
    }>;
    errors: Array<{
        employee_id: number;
        employee_name: string;
        employee_no: string | null;
        message: string;
        field: string | null;
        field_label: string | null;
        employee_url: string;
    }>;
};

export type EmployeeStats = {
    total: number;
    with_bank_account: number;
    missing_bank_account: number;
    cash_payment_count: number;
};

export type PayrollRecordsSummary = {
    employee_count: number;
    total_gross: string;
    total_net: string;
    total_additions: string;
    total_deductions: string;
    total_overtime_pay: string;
    total_overtime_hours: string;
};

export type PayrollShowProps = {
    period: PayrollPeriod;
    leave_types?: LeaveTypeColumn[];
    rows: CrewPayrollRow[];
    pagination: PaginationMeta;
    all_board_employee_ids: number[];
    payroll_records: PayrollRecordListItem[];
    payroll_records_pagination: PaginationMeta | null;
    payroll_records_monthly: CrewPayrollRecordListItem[];
    payroll_records_monthly_pagination: PaginationMeta | null;
    all_payroll_record_ids: number[];
    payroll_records_summary: PayrollRecordsSummary | null;
    salary_inputs_by_employee: Record<string, SalaryInput[]>;
    salary_input_type_options: SalaryInputTypeOption[];
    generation_summary: PayrollGenerationSummary | null;
    search: string;
    filters: PayrollShowFilters;
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    permissions: CrewPayrollPermissions;
    payslip_summary: PayslipSummary;
    wps_preview: WpsPreview | null;
    timesheet_draft: CrewTimesheetFormData | null;
    employee_stats: EmployeeStats | null;
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

export function formatTimesheetAmount(
    value: string | null | undefined,
): string {
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

export function getPeriodProgressPercent(
    period: PayrollPeriodListItem,
): number {
    if (!period.supports_timesheets || period.employee_count === 0) {
        return 0;
    }

    return Math.round(
        (period.timesheets_filled_count / period.employee_count) * 100,
    );
}
