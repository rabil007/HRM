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

export type DashboardProps = {
    document_compliance: DocumentCompliance;
    employee_analytics: EmployeeAnalytics;
    workforce_trends?: WorkforceTrendPoint[];
    employees_by_department?: DistributionPoint[];
    employees_by_branch?: DistributionPoint[];
    document_health: DocumentHealthSlice[];
    organization_snapshot: {
        departments: number;
        branches: number;
    };
    recent_hires?: RecentHire[];
    attendance_analytics: AttendanceAnalytics;
};
