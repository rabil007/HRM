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

export type DashboardProps = {
    document_compliance: DocumentCompliance;
    employee_analytics: EmployeeAnalytics;
    workforce_trends: WorkforceTrendPoint[];
    employees_by_department: DistributionPoint[];
    employees_by_branch: DistributionPoint[];
    document_health: DocumentHealthSlice[];
    organization_snapshot: {
        departments: number;
        branches: number;
    };
    recent_hires: RecentHire[];
};
