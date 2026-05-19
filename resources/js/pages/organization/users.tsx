import { Head } from '@inertiajs/react';
import { UsersContent } from '@/features/organization/users';
import type { User } from '@/features/organization/users/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Users({
    users,
    pagination,
    search,
    filters,
    roles,
}: {
    users: User[];
    pagination: PaginationMeta;
    search: string;
    filters: { status: string };
    roles: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Users" />
            <UsersContent
                users={users}
                pagination={pagination}
                search={search}
                filters={filters}
                roles={roles}
            />
        </>
    );
}
