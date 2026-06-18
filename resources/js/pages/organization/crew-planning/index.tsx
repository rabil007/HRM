import { Head } from '@inertiajs/react';
import { CrewPlanningContent } from '@/features/organization/crew-planning/index';
import type {
    GanttBar,
    GanttVesselGroup,
    PlanningFilters,
    PlanningOption,
    PlanningPagePermissions,
    PlanningPoolEmployee,
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
            />
        </>
    );
}
