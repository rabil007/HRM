import { Head } from '@inertiajs/react';
import { RolesContent } from '@/features/organization/roles';
import type { Company, Role } from '@/features/organization/roles/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Roles({
    roles,
    companies,
}: {
    roles: Pagination<Role>;
    companies: Company[];
}) {
    return (
        <>
            <Head title="Roles & Permissions" />
            <RolesContent roles={roles.data} companies={companies} />
        </>
    );
}

