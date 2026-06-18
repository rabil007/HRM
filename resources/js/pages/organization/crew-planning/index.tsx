import { Head } from '@inertiajs/react';
import { CrewPlanningContent } from '@/features/organization/crew-planning/index';
import type {
    GanttBar,
    GanttVesselGroup,
    PlanningDepartmentNode,
    PlanningFilters,
    PlanningOption,
    PlanningPagePermissions,
    PlanningPoolEmployee,
    PlanningSettings,
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
    department_tree: PlanningDepartmentNode[];
    employees: PlanningPoolEmployee[];
    settings: PlanningSettings;
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
    department_tree,
    employees,
    settings,
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
                departmentTree={department_tree}
                employees={employees}
                settings={settings}
                can={can}
            />
        </>
    );
}
