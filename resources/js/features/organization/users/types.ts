export type Company = {
    id: number;
    name: string;
};

export type User = {
    id: number;
    company: { id: number; name: string | null } | null;
    role: { id: number; name: string | null } | null;
    name: string;
    email: string;
    avatar: string | null;
    status: 'active' | 'inactive' | 'suspended';
    last_login_at?: string | null;
    created_at?: string;
};

export type UserFormData = {
    company_id: number | '';
    name: string;
    email: string;
    password: string;
    avatar: string;
    status: 'active' | 'inactive' | 'suspended';
};

