export type Company = {
    id: number;
    name: string;
};

export type DepartmentOption = {
    id: number;
    company_id: number;
    name: string;
};

export type Position = {
    id: number;
    company: { id: number; name: string | null };
    department: { id: number; name: string | null } | null;
    title: string;
    grade: string | null;
    min_salary: string | number | null;
    max_salary: string | number | null;
    status: 'active' | 'inactive';
    created_at?: string;
};

export type PositionFormData = {
    company_id: number | '';
    department_id: number | '';
    title: string;
    grade: string;
    min_salary: string;
    max_salary: string;
    status: 'active' | 'inactive';
};

