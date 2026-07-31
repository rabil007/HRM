export type DocumentCompliance = {
    total_documents: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
    uploaded_this_month: number;
    compliance_rate: number;
    avg_per_employee: number;
};

export type EmployeeAnalytics = {
    total: number;
    active: number;
    inactive: number;
    on_leave: number;
    terminated: number;
    new_hires_this_month: number;
    with_user_account: number;
    without_user_account: number;
};

export type WorkforceTrendPoint = {
    month: string;
    headcount: number;
    new_hires: number;
    documents: number;
};

export type DistributionPoint = {
    name: string;
    count: number;
};

export type DocumentHealthSlice = {
    name: string;
    value: number;
    key: string;
};

export type RecentHire = {
    id: number;
    name: string;
    employee_no: string;
    hired_at: string;
};

export type AttendanceTrendPoint = {
    day: string;
    check_ins: number;
    check_outs: number;
};

export type RecentAttendanceRecord = {
    id: number;
    date: string | null;
    clock_in: string | null;
    clock_out: string | null;
    employee_name: string | null;
    employee_id: number | null;
    status: string;
    source: string | null;
};

export type AttendanceAnalytics = {
    check_ins_today: number;
    check_outs_today: number;
    events_today: number;
    present_today: number;
    late_today: number;
    absent_today: number;
    active_employees: number;
    weekly_trends: AttendanceTrendPoint[];
    recent_records: RecentAttendanceRecord[];
};

export type CrewDashboardSummary = {
    on_vessel: number;
    in_home: number;
    needs_update: number;
    total: number;
};

export type PayrollDashboardSummary = {
    draft_periods: number;
    processing_periods: number;
    last_paid_period_name: string | null;
    last_paid_period_total: number | null;
};

export type AnnouncementsDashboardSummary = {
    total: number;
    recent: Array<{
        id: number;
        title: string;
        published_at: string | null;
        status: string;
    }>;
};

export type DashboardCan = {
    employees_create: boolean;
    employees_export: boolean;
    documents_upload: boolean;
    view_audit: boolean;
};

export type PersonalSummary = {
    user_name: string;
    company_name: string;
    today: string;
};

export type DashboardProps = {
    /** Always present — no permission required. */
    personal_summary: PersonalSummary;
    /** Always present — action capability flags. */
    can: DashboardCan;
    /** Present when user has `employees.view`. */
    employee_analytics?: EmployeeAnalytics;
    /** Present when user has `documents.view`. */
    document_compliance?: DocumentCompliance;
    /** Present when user has `employees.view` or `documents.view`. */
    document_health?: DocumentHealthSlice[];
    /** Present when user has `employees.view`. */
    organization_snapshot?: {
        departments: number;
        branches: number;
    };
    /** Present when user has `employees.view` or `attendance.overview.view`. */
    attendance_analytics?: AttendanceAnalytics;
    /** Deferred — present when user has `employees.view`. */
    workforce_trends?: WorkforceTrendPoint[];
    /** Deferred — present when user has `employees.view`. */
    employees_by_department?: DistributionPoint[];
    /** Deferred — present when user has `employees.view`. */
    employees_by_branch?: DistributionPoint[];
    /** Deferred — present when user has `employees.view`. */
    recent_hires?: RecentHire[];
    /** Present when user has `crew_operations.overview.view`. */
    crew_summary?: CrewDashboardSummary;
    /** Present when user has `payroll.overview.view`. */
    payroll_summary?: PayrollDashboardSummary;
    /** Present when user has `announcements.view`. */
    announcements_summary?: AnnouncementsDashboardSummary;
};
