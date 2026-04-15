export type BranchOption = {
    id: number;
    name: string | null;
};

export type DepartmentOption = {
    id: number;
    name: string | null;
};

export type PositionOption = {
    id: number;
    department_id: number | null;
    title: string | null;
};

export type ManagerOption = {
    id: number;
    employee_no: string;
    first_name: string;
    last_name: string;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type Employee = {
    id: number;
    user_id: number | null;
    branch_id: number | null;
    department_id: number | null;
    position_id: number | null;
    manager_id: number | null;
    employee_no: string;
    first_name: string;
    last_name: string;
    name: string;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    work_email: string | null;
    phone: string | null;
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    hire_date: string;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    created_at: string;
};

export type EmployeeFormData = {
    user_id: number | '';
    branch_id: number | '';
    department_id: number | '';
    position_id: number | '';
    manager_id: number | '';
    employee_no: string;
    first_name: string;
    last_name: string;
    work_email: string;
    phone: string;
    hire_date: string;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
};

