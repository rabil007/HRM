import { Head } from '@inertiajs/react';
import { BranchesContent } from '@/features/organization/branches';
import type { Branch, Country } from '@/features/organization/branches/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Branches({
    branches,
    pagination,
    search,
    filters,
    countries,
}: {
    branches: Branch[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        country: string;
        status: string;
        city: string;
        headquartersOnly: boolean;
        hasEmail: boolean;
        hasPhone: boolean;
    };
    countries: Country[];
}) {
    return (
        <>
            <Head title="Branches Management" />
            <BranchesContent
                branches={branches}
                pagination={pagination}
                search={search}
                filters={filters}
                countries={countries}
            />
        </>
    );
}
