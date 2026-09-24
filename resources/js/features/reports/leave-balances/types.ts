import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type BalanceReportOption = {
    id: number;
    name: string;
};

export type BalanceEmployeeOption = BalanceReportOption & {
    employee_no: string | null;
    status: string | null;
};

export type BalanceLeaveTypeOption = BalanceReportOption & {
    code: string | null;
};

export type BalanceSelectOption = {
    value: string;
    label: string;
};

export type LeaveBalanceReportRow = {
    id: number;
    employee: {
        id: number | null;
        employee_no: string | null;
        name: string;
        image: string | null;
        can_view: boolean;
        status: string | null;
        status_label: string | null;
    };
    department: BalanceReportOption | null;
    leave_type: {
        id: number | null;
        name: string;
        code: string | null;
        category: string | null;
        category_label: string | null;
    };
    year: number;
    base_entitlement: number;
    carried_days: number;
    total_available: number;
    opening_used_days: number;
    opening_balance_as_of: string | null;
    used_days: number;
    total_used_days: number;
    pending_days: number;
    remaining_days: number;
    can_edit_opening: boolean;
};

export type LeaveBalanceReportFilters = {
    search: string;
    year: string;
    employee_id: string;
    department_id: string;
    leave_type_id: string;
    category: string;
    employee_status: string;
};

export type LeaveBalanceReportProps = {
    balances: LeaveBalanceReportRow[];
    pagination: PaginationMeta;
    summary: {
        employees: number;
        balance_rows: number;
        used_days: number;
        pending_days: number;
    };
    filters: LeaveBalanceReportFilters;
    filter_options: {
        years: number[];
        employees: BalanceEmployeeOption[];
        departments: BalanceReportOption[];
        leave_types: BalanceLeaveTypeOption[];
        categories: BalanceSelectOption[];
        employee_statuses: BalanceSelectOption[];
    };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    business_year: number;
    company_today: string;
    can: {
        export: boolean;
        update_opening: boolean;
    };
};
