import { Head } from '@inertiajs/react';
import { HikvisionDevicesContent } from '@/features/hikvision/devices';
import type { HikvisionDevice } from '@/features/hikvision/devices/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    devices: HikvisionDevice[];
    pagination: PaginationMeta;
    is_configured: boolean;
    last_synced_at: string | null;
    can: {
        sync: boolean;
    };
};

export default function HikvisionDevices({
    devices,
    pagination,
    is_configured,
    last_synced_at,
    can,
}: Props) {
    return (
        <>
            <Head title="Hikvision Devices" />
            <HikvisionDevicesContent
                devices={devices}
                pagination={pagination}
                isConfigured={is_configured}
                lastSyncedAt={last_synced_at}
                can={can}
            />
        </>
    );
}
