import { Head } from '@inertiajs/react';
import { HikvisionPersonsContent } from '@/features/hikvision/persons';
import type { HikvisionPerson } from '@/features/hikvision/persons/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    persons: HikvisionPerson[];
    pagination: PaginationMeta;
    is_configured: boolean;
    last_synced_at: string | null;
    can: {
        sync: boolean;
    };
};

export default function HikvisionPersons({
    persons,
    pagination,
    is_configured,
    last_synced_at,
    can,
}: Props) {
    return (
        <>
            <Head title="Hikvision Persons" />
            <HikvisionPersonsContent
                persons={persons}
                pagination={pagination}
                isConfigured={is_configured}
                lastSyncedAt={last_synced_at}
                can={can}
            />
        </>
    );
}
