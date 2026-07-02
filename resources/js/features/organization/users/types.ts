export type Company = {
    id: number;
    name: string;
};

export type LinkedEmployee = {
    id: number;
    name: string;
    employee_no: string;
    image_url: string | null;
};

export type EmployeeForLinking = LinkedEmployee & {
    user_id: number | null;
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
    linked_employee?: LinkedEmployee | null;
};

export type UserFormData = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    avatar: File | null;
    use_employee_avatar: boolean;
    employee_id: number | '';
    role_id: number | '';
    status: 'active' | 'inactive' | 'suspended';
};
