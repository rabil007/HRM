import { Head } from '@inertiajs/react';
import { UsersContent } from '@/features/organization/users';
import type {
    EmployeeForLinking,
    User,
    UserDirectorySummary,
    UserInvitation,
} from '@/features/organization/users/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Users({
    users,
    pagination,
    search,
    filters,
    summary,
    roles,
    invitations,
    employees_for_linking,
}: {
    users: User[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        status: string;
        role_id: string;
        presence: string;
        view: string;
    };
    roles: { id: number; name: string }[];
    summary: UserDirectorySummary;
    invitations: UserInvitation[];
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
                summary={summary}
                roles={roles}
                invitations={invitations}
                employeesForLinking={employees_for_linking}
            />
        </>
    );
}
