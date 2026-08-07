import { Head } from '@inertiajs/react';
import { CrewPlanningContent } from '@/features/organization/crew-planning/index';
import type {
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

type Props = {
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
};

export default function CrewPlanningIndex({
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
}: Props) {
    return (
        <>
            <Head title="Crew Planning" />
            <CrewPlanningContent
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
            />
        </>
    );
}
