export type Company = {
    id: number;
    name: string;
};

export type Country = {
    code: string;
    name: string;
    dial_code: string | null;
};

export type Branch = {
    id: number;
    company: { id: number; name: string | null };
    name: string;
    code: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_headquarters: boolean;
    status: 'active' | 'inactive';
};

export type BranchFormData = {
    company_id: number | '';
    name: string;
    code: string;
    address: string;
    city: string;
    country: string;
    phone: string;
    email: string;
    is_headquarters: boolean;
    status: 'active' | 'inactive';
};

