import { Head } from '@inertiajs/react';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import type {
    RankOption,
    VesselManningPagePermissions,
} from '@/features/organization/vessel-manning/types';
import { VesselShowContent } from '@/features/organization/vessels/show';
import type {
    ClientOption,
    VesselDetails,
    VesselManningHealth,
    VesselPageCan,
    VesselSummary,
    VesselTypeOption,
} from '@/features/organization/vessels/types';

export default function VesselShow({
    vessel,
    vessel_types,
    clients = [],
    summary,
    can,
    recent_activity,
    can_view_audit,
    back_query,
    crew_positions,
    manning_can,
    manning_health,
}: {
    vessel: VesselDetails;
    vessel_types: VesselTypeOption[];
    clients?: ClientOption[];
    summary: VesselSummary;
    can: VesselPageCan;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    back_query?: Record<string, string>;
    crew_positions?: RankOption[];
    manning_can?: VesselManningPagePermissions;
    manning_health?: VesselManningHealth | null;
}) {
    return (
        <>
            <Head title={`Vessel • ${vessel.name}`} />
            <VesselShowContent
                vessel={vessel}
                vessel_types={vessel_types}
                clients={clients}
                summary={summary}
                can={can}
                recent_activity={recent_activity}
                can_view_audit={can_view_audit}
                back_query={back_query}
                crew_positions={crew_positions}
                manning_can={manning_can}
                manning_health={manning_health}
            />
        </>
    );
}
