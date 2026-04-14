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
    company,
    permissions,
}: {
    roles: Pagination<Role>;
    company: Company | null;
    permissions: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Roles & Permissions" />
            <RolesContent roles={roles.data} company={company} permissions={permissions} />
        </>
    );
}

