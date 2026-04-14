import { Head } from '@inertiajs/react';
import { UsersContent } from '@/features/organization/users';
import type { Company, User } from '@/features/organization/users/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Users({
    users,
    companies,
}: {
    users: Pagination<User>;
    companies: Company[];
}) {
    return (
        <>
            <Head title="Users" />
            <UsersContent users={users.data} companies={companies} />
        </>
    );
}

