export type Company = {
    id: number;
    name: string;
};

export type PermissionOption = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    group: string;
};

export type PlanningDepartmentNode = {
    id: number;
    name: string;
    children: PlanningDepartmentNode[];
};

export type Role = {
    id: number;
    name: string;
    employee_visibility_scope?: 'all' | 'selected_departments';
    department_ids?: number[];
    permissions: string[];
    created_at?: string;
};

export type RoleFormData = {
    name: string;
    employee_visibility_scope?: 'all' | 'selected_departments';
    department_ids?: number[];
};
