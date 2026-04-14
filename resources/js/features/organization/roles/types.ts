export type Company = {
    id: number;
    name: string;
};

export type Role = {
    id: number;
    company: { id: number; name: string | null };
    name: string;
    slug: string;
    permissions: string[];
    is_system: boolean;
    created_at?: string;
};

export type RoleFormData = {
    company_id: number | '';
    name: string;
    slug: string;
    permissions: string[];
    is_system: boolean;
};

