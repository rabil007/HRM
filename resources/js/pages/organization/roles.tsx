import { Head } from '@inertiajs/react';
import { RolesContent } from '@/features/organization/roles';
import type { Company, Role } from '@/features/organization/roles/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Roles({
    roles,
    pagination,
    search,
    filters,
    company,
    permissions,
}: {
    roles: Role[];
    pagination: PaginationMeta;
    search: string;
    filters: { has_permissions: string };
    company: Company | null;
    permissions: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Roles & Permissions" />
            <RolesContent
                roles={roles}
                pagination={pagination}
                search={search}
                filters={filters}
                company={company}
                permissions={permissions}
            />
        </>
    );
}
