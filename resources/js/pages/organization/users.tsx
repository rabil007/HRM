import { Head } from '@inertiajs/react';
import { UsersContent } from '@/features/organization/users';
import type { User } from '@/features/organization/users/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Users({
    users,
    roles,
}: {
    users: Pagination<User>;
    roles: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Users" />
            <UsersContent users={users.data} roles={roles} />
        </>
    );
}

