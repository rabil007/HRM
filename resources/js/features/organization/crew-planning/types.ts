import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';
import type { PaginationMeta } from '@/types/pagination';

export type GanttPositionRow = {
    row_key: string;
    position_id: number;
    position_name: string;
    required_count: number;
};

export type GanttVesselGroup = {
    vessel_id: number;
    vessel_name: string;
    positions: GanttPositionRow[];
};

export type PlanningKind =
    | 'vacant_slot'
    | 'planned'
    | 'planned_relief'
    | 'assignment_created';

export type GanttBar = {
    id: number;
    row_key: string;
    employee_id: number | null;
    employee_name: string;
    start: string;
    end: string;
    planned_arrival_date?: string | null;
    planned_join_date: string;
    planned_leave_date: string | null;
    is_open_ended: boolean;
    total_days: number;
    position_name: string | null;
    vessel_name: string | null;
    notes: string | null;
    crew_assignment_id: number | null;
    relieves_crew_assignment_id: number | null;
    relieves_employee_name: string | null;
    relieves_assignment_no?: string | null;
    relieves_vessel_name?: string | null;
    relieves_position_name?: string | null;
    relieves_planned_signoff_at?: string | null;
    is_assigned: boolean;
    planning_kind?: PlanningKind;
    planning_kind_label?: string;
};

export type PlanningReliefPrefill = {
    vessel_id: number | null;
    position_id: number | null;
    relieves_crew_assignment_id: number | null;
    planned_join_date: string | null;
    open_create: boolean;
    planning_assignment_id: number | null;
    relieves_employee_name: string | null;
};

export type TreeCrewMember = {
    employee_id: number | null;
    employee_name: string;
    is_assigned: boolean;
    relieves_employee_name: string | null;
};

export type TreePosition = {
    position_id: number;
    position_name: string;
    required_count: number;
    crew: TreeCrewMember[];
};

export type TreeVessel = {
    vessel_id: number;
    vessel_name: string;
    positions: TreePosition[];
};

export type CrewPlanningView = 'planning' | 'onboard-vessels';

export type PlanningFilters = {
    vessel_id: number | null;
    position_id: number | null;
    from: string;
    to: string;
    search: string;
};

export type PlanningBackQuery = Record<string, string | number>;

export type PlanningPoolEmployee = {
    id: number;
    name: string;
    position_id: number;
    position_name: string;
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
    projection: boolean;
    create_assignment?: boolean;
    start_assignment?: boolean;
    view_assignments?: boolean;
    view_vessels?: boolean;
    view_employees?: boolean;
};

export type PlanningProjectionStatus =
    | 'covered'
    | 'covered_by_incoming'
    | 'current_gap'
    | 'future_gap'
    | 'overlap';

export type PlanningProjectionPeriod = {
    from: string;
    to: string;
    projected_count: number;
    gap: number;
    excess: number;
};

export type PlanningProjectionRow = {
    row_key: string;
    vessel_id: number;
    vessel_name: string;
    position_id: number;
    position_name: string;
    required_count: number;
    status: PlanningProjectionStatus;
    next_gap_date: string | null;
    minimum_projected_count: number;
    maximum_gap: number;
    periods: PlanningProjectionPeriod[];
};

export type PlanningProjectionSummary = {
    positions: number;
    current_gap_positions: number;
    future_gap_positions: number;
    covered_positions: number;
    overlap_positions: number;
    total_projected_shortfall_days: number;
};

export type PlanningProjection = {
    from: string;
    to: string;
    summary: PlanningProjectionSummary;
    rows: PlanningProjectionRow[];
};

export type PlanningSettings = {
    max_home_days: number;
    sync_sea_service: boolean;
    sync_training_to_employee_training?: boolean;
    allow_future_actual_movement_dates?: boolean;
    notifications_enabled: boolean;
    notification_recipient_user_ids: number[];
    alert_signoff_overdue: boolean;
    alert_signoff_no_relief: boolean;
    alert_relief_not_ready: boolean;
    alert_current_manning_gap: boolean;
    alert_projected_manning_gap: boolean;
    alert_scheduled_movement_needs_attention: boolean;
    notification_email_delivery_mode: 'scheduled' | 'immediate';
    notification_email_digest_at: string;
    notification_email_critical_immediate: boolean;
};

export type NotificationUserOption = {
    id: number;
    name: string;
    email: string;
};

export type AssignmentFormData = {
    vessel_id: string;
    position_id: string;
    employee_id: string;
    planned_arrival_date: string;
    planned_join_date: string;
    planned_leave_date: string;
    notes: string;
    relieves_crew_assignment_id: string;
};

export type CrewDragData = {
    type: 'crew';
    employeeId: number;
    employeeName: string;
    positionId: number;
    positionName: string;
};
export type RowDropData = {
    type: 'row';
    vesselId: number;
    positionId: number;
};
