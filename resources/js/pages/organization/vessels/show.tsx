import { Head } from '@inertiajs/react';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import type {
    RankOption,
    VesselManningPagePermissions,
} from '@/features/organization/vessel-manning/types';
import { VesselShowContent } from '@/features/organization/vessels/show';
import type {
    VesselDetails,
    VesselPageCan,
    VesselSummary,
    VesselTypeOption,
} from '@/features/organization/vessels/types';

export default function VesselShow({
    vessel,
    vessel_types,
    summary,
    can,
    recent_activity,
    can_view_audit,
    back_query,
    ranks,
    manning_can,
}: {
    vessel: VesselDetails;
    vessel_types: VesselTypeOption[];
    summary: VesselSummary;
    can: VesselPageCan;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    back_query?: Record<string, string>;
    ranks?: RankOption[];
    manning_can?: VesselManningPagePermissions;
}) {
    return (
        <>
            <Head title={`Vessel • ${vessel.name}`} />
            <VesselShowContent
                vessel={vessel}
                vessel_types={vessel_types}
                summary={summary}
                can={can}
                recent_activity={recent_activity}
                can_view_audit={can_view_audit}
                back_query={back_query}
                ranks={ranks}
                manning_can={manning_can}
            />
        </>
    );
}
