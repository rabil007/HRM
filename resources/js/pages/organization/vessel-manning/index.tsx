import { Head } from '@inertiajs/react';
import { VesselManningContent } from '@/features/organization/vessel-manning/index';
import type {
    RankOption,
    VesselManningItem,
    VesselManningPagePermissions,
    VesselTypeOption,
} from '@/features/organization/vessel-manning/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    vessels: VesselManningItem[];
    pagination: PaginationMeta;
    search: string;
    filters: { vessel_type_id: number | null };
    crew_positions: RankOption[];
    vessel_types: VesselTypeOption[];
    can: VesselManningPagePermissions;
};

export default function VesselManningIndex({
    vessels,
    pagination,
    search,
    filters,
    crew_positions,
    vessel_types,
    can,
}: Props) {
    return (
        <>
            <Head title="Vessel Manning" />
            <VesselManningContent
                vessels={vessels}
                pagination={pagination}
                search={search}
                filters={filters}
                crew_positions={crew_positions}
                vessel_types={vessel_types}
                can={can}
            />
        </>
    );
}
