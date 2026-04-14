export type Company = {
    id: number;
    name: string;
};

export type Role = {
    id: number;
    name: string;
    permissions: string[];
    created_at?: string;
};

export type RoleFormData = {
    name: string;
};

