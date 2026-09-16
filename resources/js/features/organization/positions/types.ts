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
    department: {
        id: number;
        name: string | null;
        parent?: { id: number; name: string | null } | null;
    } | null;
    users_count?: number;
    title: string;
    description: string | null;
    grade: string | null;
    min_salary: string | number | null;
    max_salary: string | number | null;
    status: 'active' | 'inactive';
    attachment: {
        original_name: string;
        mime_type: string | null;
        size_bytes: number | null;
        is_image: boolean;
        preview_url: string;
        download_url: string;
    } | null;
    created_at?: string;
};

export type PositionFormData = {
    department_id: number | '';
    title: string;
    description: string;
    grade: string;
    min_salary: string;
    max_salary: string;
    status: 'active' | 'inactive';
    attachment: File | null;
    remove_attachment: boolean;
};
