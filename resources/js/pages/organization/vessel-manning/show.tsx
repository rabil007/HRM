import { Head } from '@inertiajs/react';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { VesselManningShowContent } from '@/features/organization/vessel-manning/show';
import type {
    PositionOption,
    VesselManningPagePermissions,
    VesselManningShowItem,
} from '@/features/organization/vessel-manning/types';

type Props = {
    vessel: VesselManningShowItem;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    can: VesselManningPagePermissions;
    crew_positions: PositionOption[];
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
