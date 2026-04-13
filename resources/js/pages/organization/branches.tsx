import { Head } from '@inertiajs/react';
import { BranchesContent } from '@/features/organization/branches';
import type { Branch, Company, Country } from '@/features/organization/branches/types';

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

