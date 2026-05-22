import { Head } from '@inertiajs/react';
import { UsersContent } from '@/features/organization/users';
import type { EmployeeForLinking, User } from '@/features/organization/users/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Users({
    users,
    pagination,
    search,
    filters,
    roles,
    employees_for_linking,
}: {
    users: User[];
    pagination: PaginationMeta;
    search: string;
    filters: { status: string };
    roles: { id: number; name: string }[];
    employees_for_linking: EmployeeForLinking[];
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
                employeesForLinking={employees_for_linking}
            />
        </>
    );
}
