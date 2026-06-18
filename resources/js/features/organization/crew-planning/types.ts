export type GanttRankRow = {
    row_key: string;
    rank_id: number;
    rank_name: string;
    required_count: number;
};

export type GanttVesselGroup = {
    vessel_id: number;
    vessel_name: string;
    ranks: GanttRankRow[];
};

export type GanttBar = {
    id: number;
    row_key: string;
    employee_id: number | null;
    employee_name: string;
    start: string;
    end: string;
    planned_join_date: string;
    planned_leave_date: string;
    rank_name: string | null;
    vessel_name: string | null;
    notes: string | null;
};

export type TreeCrewMember = {
    employee_id: number | null;
    employee_name: string;
};

export type TreeRank = {
    rank_id: number;
    rank_name: string;
    required_count: number;
    crew: TreeCrewMember[];
};

export type TreeVessel = {
    vessel_id: number;
    vessel_name: string;
    ranks: TreeRank[];
};

export type PlanningFilters = {
    vessel_id: number | null;
    rank_id: number | null;
    from: string;
    to: string;
    search: string;
};

export type PlanningPoolEmployee = {
    id: number;
    name: string;
    rank_id: number;
    rank_name: string;
};

export type PlanningDepartmentNode = {
    id: number;
    name: string;
    children: PlanningDepartmentNode[];
};

export type PlanningOption = {
    id: number;
    name: string;
};

export type PlanningPagePermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
};

export type PlanningSettings = {
    pool_department_ids: number[];
};

export type AssignmentFormData = {
    vessel_id: string;
    rank_id: string;
    employee_id: string;
    planned_join_date: string;
    planned_leave_date: string;
    notes: string;
};

export type CrewDragData = {
    type: 'crew';
    employeeId: number;
    employeeName: string;
    rankId: number;
    rankName: string;
};
export type RowDropData = { type: 'row'; vesselId: number; rankId: number };
