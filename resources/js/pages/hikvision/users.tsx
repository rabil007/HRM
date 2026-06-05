import { Head } from '@inertiajs/react';
import { HikvisionUsersContent } from '@/features/hikvision/users';
import type { HikvisionUser } from '@/features/hikvision/users/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    users: HikvisionUser[];
    pagination: PaginationMeta;
    is_configured: boolean;
    last_synced_at: string | null;
    can: {
        sync: boolean;
    };
};

export default function HikvisionUsers({
    users,
    pagination,
    is_configured,
    last_synced_at,
    can,
}: Props) {
    return (
        <>
            <Head title="Hikvision Users" />
            <HikvisionUsersContent
                users={users}
                pagination={pagination}
                isConfigured={is_configured}
                lastSyncedAt={last_synced_at}
                can={can}
            />
        </>
    );
}
