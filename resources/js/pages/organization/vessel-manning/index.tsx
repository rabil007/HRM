import { Head } from '@inertiajs/react';
import { VesselManningContent } from '@/features/organization/vessel-manning/index';
import type {
    RankOption,
    VesselManningItem,
    VesselTypeOption,
} from '@/features/organization/vessel-manning/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    vessels: VesselManningItem[];
    pagination: PaginationMeta;
    search: string;
    filters: { vessel_type_id: number | null };
    ranks: RankOption[];
    vessel_types: VesselTypeOption[];
    can: { manage: boolean };
};

export default function VesselManningIndex({
    vessels,
    pagination,
    search,
    filters,
    ranks,
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
                ranks={ranks}
                vessel_types={vessel_types}
                can={can}
            />
        </>
    );
}
