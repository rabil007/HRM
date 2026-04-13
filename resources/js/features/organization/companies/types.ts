export type Company = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    industry: string | null;
    city: string | null;
    country: { id: number; code: string | null; name: string | null };
    company_size: string | null;
    registration_number: string | null;
    tax_id: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    currency: { id: number; code: string | null };
    timezone: string | null;
    payroll_cycle: string | null;
    working_days: number[] | null;
    wps_agent_code: string | null;
    wps_mol_uid: string | null;
    status: 'active' | 'suspended' | 'inactive' | null;
};

export type Currency = { id: number; code: string; name: string; symbol: string | null };

export type Country = { id: number; code: string; name: string; dial_code: string | null };

export type CompanyFormData = {
    logo: File | null;
    name: string;
    industry: string;
    company_size: string;
    registration_number: string;
    tax_id: string;
    city: string;
    address: string;
    phone: string;
    country_id: number | '';
    email: string;
    website: string;
    currency_id: number | '';
    timezone: string;
    payroll_cycle: 'monthly' | 'biweekly' | 'weekly';
    working_days: number[];
    wps_agent_code: string;
    wps_mol_uid: string;
    status: 'active' | 'suspended' | 'inactive';
};

