import { Head } from '@inertiajs/react';
import { CompaniesContent } from '@/features/organization/companies';
import type { Company, Country, Currency } from '@/features/organization/companies/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Companies({
    companies,
    pagination,
    search,
    filters,
    countries,
    currencies,
}: {
    companies: Company[];
    pagination: PaginationMeta;
    search: string;
    filters: { industry: string; country: string; currency: string };
    countries: Country[];
    currencies: Currency[];
}) {
    return (
        <>
            <Head title="Companies Management" />
            <CompaniesContent
                companies={companies}
                pagination={pagination}
                search={search}
                filters={filters}
                countries={countries}
                currencies={currencies}
            />
        </>
    );
}
