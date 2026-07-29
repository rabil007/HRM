export type Company = {
    id: number;
    name: string;
};

export type Branch = {
    id: number;
    company_id: number;
    name: string;
    company?: { id: number; name: string | null };
};

export type Manager = {
    id: number;
    name: string;
    employee_no?: string | null;
};

export type DepartmentParentOption = {
    id: number;
    company_id: number;
    name: string;
    parent_id: number | null;
};

export type LeaveApprovalPolicyOption = {
    id: number;
    name: string;
    is_default?: boolean;
};

export type ManagerAssignment = {
    type: 'direct' | 'inherited' | 'none';
    label: string;
    source_department: { id: number; name: string } | null;
};

export type LeaveApprovalPolicyAssignment = {
    type: 'direct' | 'inherited' | 'company_default' | 'none';
    label: string;
    source_department: { id: number; name: string } | null;
};

export type Department = {
    id: number;
    company: { id: number; name: string | null };
    branch: { id: number; name: string | null } | null;
    parent: { id: number; name: string | null } | null;
    manager: {
        id: number;
        name: string | null;
        employee_no?: string | null;
    } | null;
    manager_assignment?: ManagerAssignment;
    leave_approval_policy_id?: number | null;
    leave_approval_policy?: { id: number; name: string } | null;
    leave_approval_policy_assignment?: LeaveApprovalPolicyAssignment;
    name: string;
    code: string | null;
    status: 'active' | 'inactive';
    created_at?: string;
};

export type DepartmentFormData = {
    branch_id: number | '';
    parent_id: number | '';
    manager_id: number | '';
    leave_approval_policy_id: number | '';
    name: string;
    code: string;
    status: 'active' | 'inactive';
};
