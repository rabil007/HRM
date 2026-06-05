import { Head } from '@inertiajs/react';
import { HikvisionAccessEventsContent } from '@/features/hikvision/access-events';
import type {
    HikvisionAccessEvent,
    HikvisionAccessEventFilters,
    HikvisionAttendanceStatusOption,
    HikvisionEventsFetchStatus,
} from '@/features/hikvision/access-events/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    events: HikvisionAccessEvent[];
    pagination: PaginationMeta;
    filters: HikvisionAccessEventFilters;
    attendance_status_options: HikvisionAttendanceStatusOption[];
    device_options: HikvisionAttendanceStatusOption[];
    attendance_lookback_days: number;
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
    filters,
    attendance_status_options,
    device_options,
    attendance_lookback_days,
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
                filters={filters}
                attendanceStatusOptions={attendance_status_options}
                deviceOptions={device_options}
                attendanceLookbackDays={attendance_lookback_days}
                isConfigured={is_configured}
                lastFetchedAt={last_fetched_at}
                fetchStatus={fetch_status}
                fetchMessage={fetch_message}
                can={can}
            />
        </>
    );
}
