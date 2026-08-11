import { Head } from '@inertiajs/react';
import { VesselsContent } from '@/features/organization/vessels/index';
import type {
    VesselPageCan,
    VesselRow,
    VesselTypeOption,
} from '@/features/organization/vessels/types';
import type { PaginationMeta } from '@/types/pagination';

export default function VesselsIndex({
    vessels,
    pagination,
    search,
    filters,
    vessel_types,
    can,
}: {
    vessels: VesselRow[];
    pagination: PaginationMeta;
    search: string;
    filters: { vessel_type_id: number | null };
    vessel_types: VesselTypeOption[];
    can: VesselPageCan;
}) {
    return (
        <>
            <Head title="Vessels" />
            <VesselsContent
                vessels={vessels}
                pagination={pagination}
                search={search}
                filters={filters}
                vessel_types={vessel_types}
                can={can}
            />
        </>
    );
}
