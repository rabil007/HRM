import { Head } from '@inertiajs/react';
import { VesselManningShowContent } from '@/features/organization/vessel-manning/show';
import type {
    RankOption,
    VesselManningPagePermissions,
    VesselManningShowItem,
} from '@/features/organization/vessel-manning/types';
import type { RecentActivityItem } from '@/components/recent-activity-card';

type Props = {
    vessel: VesselManningShowItem;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    can: VesselManningPagePermissions;
    ranks: RankOption[];
    back_query: Record<string, string>;
};

export default function VesselManningShow({ vessel, ...props }: Props) {
    return (
        <>
            <Head title={vessel.name} />
            <VesselManningShowContent vessel={vessel} {...props} />
        </>
    );
}
