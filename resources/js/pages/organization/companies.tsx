import { Head } from '@inertiajs/react';
import { CompaniesContent } from '@/features/organization/companies';

type Company = {
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
    created_at: string;
};

type Currency = { id: number; code: string; name: string; symbol: string | null };
type Country = { id: number; code: string; name: string; dial_code: string | null };

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Companies({
    companies,
    countries,
    currencies,
}: {
    companies: Pagination<Company>;
    countries: Country[];
    currencies: Currency[];
}) {
    return (
        <>
            <Head title="Companies Management" />
            <CompaniesContent companies={companies.data} countries={countries} currencies={currencies} />
        </>
    );
}
