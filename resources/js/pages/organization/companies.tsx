import { Head } from '@inertiajs/react';
import { CompaniesContent } from '@/features/organization/companies';
import type { Company, Country, Currency } from '@/features/organization/companies/types';

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
