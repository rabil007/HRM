import { Head } from '@inertiajs/react';
import { HikvisionAccessEventsContent } from '@/features/hikvision/access-events';
import type {
    HikvisionAccessEvent,
    HikvisionEventsFetchStatus,
} from '@/features/hikvision/access-events/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    events: HikvisionAccessEvent[];
    pagination: PaginationMeta;
    is_configured: boolean;
    last_fetched_at: string | null;
    fetch_status: HikvisionEventsFetchStatus;
    fetch_message: string | null;
    can: {
        fetch: boolean;
    };
};

export default function HikvisionAccessEvents({
    events,
    pagination,
    is_configured,
    last_fetched_at,
    fetch_status,
    fetch_message,
    can,
}: Props) {
    return (
        <>
            <Head title="Hikvision Access Events" />
            <HikvisionAccessEventsContent
                events={events}
                pagination={pagination}
                isConfigured={is_configured}
                lastFetchedAt={last_fetched_at}
                fetchStatus={fetch_status}
                fetchMessage={fetch_message}
                can={can}
            />
        </>
    );
}
