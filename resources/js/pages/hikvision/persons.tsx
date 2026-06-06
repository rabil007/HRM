import { Head } from '@inertiajs/react';
import { HikvisionPersonsContent } from '@/features/hikvision/persons';
import type {
    HikvisionPerson,
    HikvisionPersonFilterOption,
    HikvisionPersonFilters,
} from '@/features/hikvision/persons/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    persons: HikvisionPerson[];
    pagination: PaginationMeta;
    filters: HikvisionPersonFilters;
    group_options: HikvisionPersonFilterOption[];
    credential_options: HikvisionPersonFilterOption[];
    is_configured: boolean;
    last_synced_at: string | null;
    can: {
        sync: boolean;
    };
};

export default function HikvisionPersons({
    persons,
    pagination,
    filters,
    group_options,
    credential_options,
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
                filters={filters}
                groupOptions={group_options}
                credentialOptions={credential_options}
                isConfigured={is_configured}
                lastSyncedAt={last_synced_at}
                can={can}
            />
        </>
    );
}
