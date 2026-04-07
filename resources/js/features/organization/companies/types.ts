export type Company = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    industry: string | null;
    city: string | null;
    country: { id: number; code: string | null; name: string | null };
    email: string | null;
    website: string | null;
    currency: { id: number; code: string | null };
};

export type Currency = { id: number; code: string; name: string; symbol: string | null };

export type Country = { id: number; code: string; name: string; dial_code: string | null };

export type CompanyFormData = {
    logo: File | null;
    name: string;
    industry: string;
    city: string;
    country_id: number | '';
    email: string;
    website: string;
    currency_id: number | '';
};

