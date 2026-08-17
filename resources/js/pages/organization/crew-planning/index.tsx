import { Head } from '@inertiajs/react';
import type { CurrentCrewVesselRow } from '@/features/organization/crew/types';
import { CrewPlanningContent } from '@/features/organization/crew-planning/index';
import type {
    CrewPlanningView,
    GanttBar,
    GanttVesselGroup,
    PlanningFilters,
    PlanningOption,
    PlanningPagePermissions,
    PlanningPoolEmployee,
    PlanningProjection,
    PlanningReliefPrefill,
    TreeVessel,
} from '@/features/organization/crew-planning/types';
import type { PaginationMeta } from '@/types/pagination';

type Props = {
    view?: CrewPlanningView;
    rows: GanttVesselGroup[];
    bars: GanttBar[];
    tree: TreeVessel[];
    filters: PlanningFilters;
    today: string;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    employees: PlanningPoolEmployee[];
    can: PlanningPagePermissions;
    projection?: PlanningProjection | null;
    relief_prefill?: PlanningReliefPrefill | null;
    onboard_vessels?: CurrentCrewVesselRow[];
    onboard_pagination?: PaginationMeta;
};

export default function CrewPlanningIndex({
    view = 'planning',
    rows,
    bars,
    tree,
    filters,
    today,
    vessels,
    ranks,
    employees,
    can,
    projection = null,
    relief_prefill = null,
    onboard_vessels = [],
    onboard_pagination,
}: Props) {
    return (
        <>
            <Head title="Crew Planning" />
            <CrewPlanningContent
                view={view}
                rows={rows}
                bars={bars}
                tree={tree}
                filters={filters}
                today={today}
                vessels={vessels}
                ranks={ranks}
                employees={employees}
                can={can}
                projection={projection}
                relief_prefill={relief_prefill}
                onboard_vessels={onboard_vessels}
                onboard_pagination={onboard_pagination}
            />
        </>
    );
}
