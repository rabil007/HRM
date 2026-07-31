export type PersonalSummary = {
    user_name: string;
    company_name: string;
    today: string;
};

export type DashboardCan = {
    employees_create: boolean;
    employees_export: boolean;
    documents_upload: boolean;
    contracts_create: boolean;
    attendance_leave_approve: boolean;
    payroll_periods_create: boolean;
    payroll_periods_approve: boolean;
    crew_planning_create: boolean;
    announcements_publish: boolean;
    signatures_review: boolean;
    view_audit: boolean;
};

export type DashboardAttentionItem = {
    key: string;
    module: string;
    title: string;
    description: string | null;
    count: number;
    severity: 'critical' | 'warning' | 'info';
    href: string;
    action_label: string;
};

export type EmployeeAnalytics = {
    total: number;
    active: number;
    inactive: number;
    on_leave: number;
    terminated: number;
    new_hires_this_month: number;
    records_added_this_month?: number;
    with_user_account: number;
    without_user_account: number;
};

export type OrganizationSnapshot = {
    departments: number;
    branches: number;
};

export type DocumentCompliance = {
    total_documents: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
    uploaded_this_month: number;
    compliance_rate: number;
    uploaded_document_validity?: number;
    avg_per_employee: number;
};

export type DocumentHealthSlice = {
    name: string;
    value: number;
    key: string;
};

export type AttendanceAnalytics = {
    check_ins_today: number;
    check_outs_today: number;
    events_today: number;
    attendance_events_today?: number;
    present_today: number;
    unique_employees_present?: number;
    late_today: number;
    absent_today: number;
    active_employees: number;
    weekly_trends: Array<{
        day: string;
        check_ins: number;
        check_outs: number;
    }>;
    recent_records: Array<{
        id: number;
        date: string | null;
        clock_in: string | null;
        clock_out: string | null;
        employee_name: string | null;
        employee_id: number | null;
        status: string;
        source: string | null;
    }>;
};

export type LeaveDashboardSummary = {
    on_leave_today: number;
    upcoming_this_week: number;
    pending_requests: number;
    awaiting_my_approval: number;
    oldest_pending_date: string | null;
};

export type ContractsDashboardSummary = {
    total_contracts: number;
    active: number;
    ending_30: number;
    ending_60: number;
    ending_90: number;
    ended: number;
    no_contract_employees: number;
};

export type TrainingDashboardSummary = {
    total: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
};

export type BankAccountsDashboardSummary = {
    total_bank_accounts: number;
    primary_accounts: number;
    secondary_accounts: number;
    ansari_accounts: number;
    no_account_employees: number;
};

export type PayrollDashboardSummary = {
    draft_periods: number;
    processing_periods: number;
    awaiting_approval_periods: number;
    last_paid_period_name: string | null;
    last_paid_period_total: number | null;
};

export type CrewDashboardSummary = {
    on_vessel: number;
    ready_to_join: number;
    in_home?: number;
    at_home: number;
    needs_update: number;
    movement_updates_required?: number;
    planned_signoffs_due: number;
    overdue_at_home: number;
    total: number;
};

export type AnnouncementsDashboardSummary = {
    published: number;
    scheduled: number;
    failed_deliveries: number;
    total: number;
    recent: Array<{
        id: number;
        title: string;
        published_at: string | null;
        status: string;
    }>;
};

export type AuditDashboardSummary = {
    recent: Array<{
        id: number;
        causer_name: string;
        description: string;
        subject_type: string;
        created_at: string;
    }>;
};

export type PersonalDashboard = {
    has_linked_employee: boolean;
    employee: {
        id: number;
        name: string;
        employee_no: string;
        position: string | null;
        department: string | null;
    } | null;
    attendance_today: {
        status: string | null;
        clock_in: string | null;
        clock_out: string | null;
        hours_worked: number | null;
    } | null;
    recent_attendance: Array<{
        id: number;
        date: string | null;
        clock_in: string | null;
        clock_out: string | null;
        status: string;
    }>;
    my_leave_requests: Array<{
        id: number;
        leave_type: string;
        start_date: string | null;
        end_date: string | null;
        total_days: number | string;
        status: string;
    }>;
    my_leave_balances: Array<{
        id: number;
        name: string;
        code: string;
        color: string | null;
        entitled_days: number;
        carried_days: number;
        used_days: number;
        pending_days: number;
        remaining_days: number;
    }>;
    my_expiring_documents: Array<{
        id: number;
        title: string;
        type: string;
        expiry_date: string | null;
        is_expired: boolean;
    }>;
    my_announcements: Array<{
        id: number;
        title: string;
        preview: string;
        priority: string;
        published_at: string | null;
        read_at: string | null;
        url: string;
    }>;
    my_payslips: Array<{
        id: number;
        period_name: string;
        net_salary: number | string;
        created_at: string | null;
    }>;
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

export type RecentHire = {
    id: number;
    name: string;
    employee_no: string;
    hired_at: string;
};

export type DashboardProps = {
    personal_summary: PersonalSummary;
    personal_dashboard?: PersonalDashboard;
    attention_items?: DashboardAttentionItem[];
    can: DashboardCan;
    employee_analytics?: EmployeeAnalytics;
    organization_snapshot?: OrganizationSnapshot;
    document_compliance?: DocumentCompliance;
    document_health?: DocumentHealthSlice[];
    attendance_analytics?: AttendanceAnalytics;
    leave_summary?: LeaveDashboardSummary;
    contracts_summary?: ContractsDashboardSummary;
    training_summary?: TrainingDashboardSummary;
    bank_accounts_summary?: BankAccountsDashboardSummary;
    crew_summary?: CrewDashboardSummary;
    payroll_summary?: PayrollDashboardSummary;
    announcements_summary?: AnnouncementsDashboardSummary;
    audit_summary?: AuditDashboardSummary;
    workforce_trends?: WorkforceTrendPoint[];
    employees_by_department?: DistributionPoint[];
    employees_by_branch?: DistributionPoint[];
    recent_hires?: RecentHire[];
};
