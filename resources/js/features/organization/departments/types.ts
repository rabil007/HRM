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
};

export type DepartmentParentOption = {
    id: number;
    company_id: number;
    name: string;
};

export type Department = {
    id: number;
    company: { id: number; name: string | null };
    branch: { id: number; name: string | null } | null;
    parent: { id: number; name: string | null } | null;
    manager: { id: number; name: string | null } | null;
    name: string;
    code: string | null;
    status: 'active' | 'inactive';
    created_at?: string;
};

export type DepartmentFormData = {
    company_id: number | '';
    branch_id: number | '';
    parent_id: number | '';
    manager_id: number | '';
    name: string;
    code: string;
    status: 'active' | 'inactive';
};

