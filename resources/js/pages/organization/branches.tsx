import { Head } from '@inertiajs/react';
import { BranchesContent } from '@/features/organization/branches';

type Company = {
    id: number;
    name: string;
};

type Country = {
    code: string;
    name: string;
    dial_code: string | null;
};

type Branch = {
    id: number;
    company: { id: number; name: string | null };
    name: string;
    code: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_headquarters: boolean;
    status: 'active' | 'inactive';
    created_at: string;
};

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Branches({
    branches,
    companies,
    countries,
}: {
    branches: Pagination<Branch>;
    companies: Company[];
    countries: Country[];
}) {
    return (
        <>
            <Head title="Branches Management" />
            <BranchesContent branches={branches.data} companies={companies} countries={countries} />
        </>
    );
}

