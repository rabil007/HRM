import type { LeaveRequestStatus } from '@/features/attendance/leave-requests/types';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type ReportOption = {
    id: number;
    name: string;
};

export type EmployeeOption = ReportOption & {
    employee_no: string | null;
};

export type LeaveTypeOption = ReportOption & {
    code: string;
    color: string | null;
    category?: string;
    category_label?: string;
};

export type LeaveReportApprovalStep = {
    sequence: number;
    policy_step_label: string | null;
    approver_employee_id: number | null;
    approver_name: string;
    status: string;
    status_label: string;
    acted_at: string | null;
    is_current_action_step: boolean;
};

export type LeaveReportReassignment = {
    sequence: number;
    policy_step_label: string | null;
    from_name: string;
    to_name: string;
    reassigned_by: string | null;
    reassigned_at: string | null;
    reason?: string | null;
};

export type LeaveReportApprovalProgress = {
    required_steps: number;
    approved_steps: number;
    current_status: string;
    waiting_for: string | null;
    current_sequence: number | null;
    label: string;
};

export type LeaveReportDayBucket = {
    approved: number;
    pending: number;
    total: number;
};

export type SelectOption = {
    value: string;
    label: string;
};

export type LeaveReportRow = {
    id: number;
    employee: {
        id: number | null;
        employee_no: string | null;
        name: string | null;
        image: string | null;
        can_view: boolean;
    };
    department: ReportOption | null;
    leave_type: LeaveTypeOption | null;
    start_date: string | null;
    end_date: string | null;
    total_days: number | null;
    status: LeaveRequestStatus;
    status_label: string;
    submitted_at: string | null;
    decided_at: string | null;
    decided_by: string | null;
    approval_progress: LeaveReportApprovalProgress;
    approval_chain: LeaveReportApprovalStep[];
    reassignments: LeaveReportReassignment[];
};

export type LeaveReportFilters = {
    search: string;
    leave_from: string;
    leave_to: string;
    employee_id: string;
    leave_type_id: string;
    status: string;
    department_id: string;
    submitted_from: string;
    submitted_to: string;
    decided_from: string;
    decided_to: string;
    sort: string;
    direction: string;
};

export type LeaveReportProps = {
    leave_requests: LeaveReportRow[];
    pagination: PaginationMeta;
    summary: {
        total_leave_days: number;
        approved_leave_days: number;
        pending_leave_days: number;
        annual: LeaveReportDayBucket;
        sick: LeaveReportDayBucket;
        total_requests: number;
        leave_types: Array<
            LeaveTypeOption & {
                request_count: number;
            }
        >;
    };
    filters: LeaveReportFilters;
    filter_options: {
        statuses: SelectOption[];
        employees: EmployeeOption[];
        leave_types: LeaveTypeOption[];
        departments: ReportOption[];
    };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    can: {
        export: boolean;
        view_employee: boolean;
    };
};
