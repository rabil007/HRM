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
        total: number;
        approved: number;
        pending: number;
        approved_leave_days: number;
        employees_taking_leave: number;
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
