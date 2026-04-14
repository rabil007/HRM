import { Head } from '@inertiajs/react';
import { BranchesContent } from '@/features/organization/branches';
import type { Branch, Country } from '@/features/organization/branches/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Branches({
    branches,
    countries,
}: {
    branches: Pagination<Branch>;
    countries: Country[];
}) {
    return (
        <>
            <Head title="Branches Management" />
            <BranchesContent branches={branches.data} countries={countries} />
        </>
    );
}

