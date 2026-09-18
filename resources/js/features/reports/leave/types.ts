import type { LeaveRequestStatus } from '@/features/attendance/leave-requests/types';
import type { PaginationMeta } from '@/types/pagination';

export type ReportOption = {
    id: number;
    name: string;
};

export type EmployeeOption = ReportOption & {
    employee_no: string | null;
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
        can_view: boolean;
    };
    department: ReportOption | null;
    branch: ReportOption | null;
    leave_type: ReportOption | null;
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
    branch_id: string;
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
        leave_types: ReportOption[];
        departments: ReportOption[];
        branches: ReportOption[];
    };
    can: {
        export: boolean;
        view_employee: boolean;
    };
};
