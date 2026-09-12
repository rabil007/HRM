import type { CrewMobilisationReadiness } from '@/features/organization/crew/types';
import type { PaginationMeta } from '@/types/pagination';

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
    planned_join_date: string;
    planned_leave_date: string | null;
    is_open_ended: boolean;
    total_days: number;
    rank_name: string | null;
    vessel_name: string | null;
    notes: string | null;
    crew_assignment_id: number | null;
    relieves_crew_assignment_id: number | null;
    relieves_employee_name: string | null;
    relieves_assignment_no?: string | null;
    relieves_vessel_name?: string | null;
    relieves_rank_name?: string | null;
    relieves_planned_signoff_at?: string | null;
    is_assigned: boolean;
    planning_kind?: PlanningKind;
    planning_kind_label?: string;
};

export type PlanningReliefPrefill = {
    vessel_id: number | null;
    rank_id: number | null;
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

export type CrewPlanningView = 'planning' | 'onboard-vessels' | 'relief';

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
    projection: boolean;
    create_assignment?: boolean;
    view_assignments?: boolean;
    view_vessels?: boolean;
    view_employees?: boolean;
};

export type ReliefDeskFocus =
    | ''
    | 'needs_relief'
    | 'critical'
    | 'not_ready'
    | 'signoff_7'
    | 'signoff_14'
    | 'ready'
    | 'overdue';

export type ReliefDeskFilters = {
    search: string;
    vessel_id: number | null;
    rank_id: number | null;
    client_id: number | null;
    relief_status: string;
    relief_risk: string;
    planned_signoff_from: string;
    planned_signoff_to: string;
    horizon: '30' | 'all';
    focus: ReliefDeskFocus;
};

export type ReliefDeskSummary = {
    needs_relief: number;
    critical: number;
    not_ready: number;
    signoff_14: number;
    ready: number;
    overdue: number;
};

export type ReliefDeskPerson = {
    id: number;
    name: string;
    employee_no: string | null;
    href: string | null;
};

export type ReliefDeskVessel = {
    id: number;
    name: string;
    href: string | null;
};

export type ReliefDeskAction = {
    key: string;
    label: string;
    href: string | null;
};

export type ReliefDeskRow = {
    id: number;
    assignment_no: string;
    source_href: string | null;
    employee: ReliefDeskPerson | null;
    vessel: ReliefDeskVessel | null;
    rank: { id: number; name: string } | null;
    current_phase_code: string | null;
    current_phase_label: string | null;
    current_duty_day: number | null;
    days_onboard: number | null;
    planned_signoff_at: string | null;
    days_until_signoff: number | null;
    missing_planned_signoff: boolean;
    relief_status: string;
    relief_status_label: string;
    relief_risk: string;
    relief_risk_label: string;
    relief_employee: ReliefDeskPerson | null;
    relief_planning_assignment_id: number | null;
    relief_crew_assignment_id: number | null;
    relief_phase_code: string | null;
    relief_phase_label: string | null;
    relief_planned_join_date: string | null;
    mobilisation_readiness: CrewMobilisationReadiness | null;
    recommended_action: ReliefDeskAction;
};

export type ReliefDeskFilterOptions = {
    clients: PlanningOption[];
    vessels?: Array<{
        id: number;
        name: string;
        client_id?: number | null;
        client_ids?: number[];
    }>;
    relief_statuses: Array<{ value: string; label: string }>;
    relief_risks: Array<{ value: string; label: string }>;
};

export type ReliefDeskPayload = {
    rows: ReliefDeskRow[];
    pagination: PaginationMeta;
    summary: ReliefDeskSummary;
    filters: ReliefDeskFilters;
    filter_options: ReliefDeskFilterOptions;
    has_active_query: boolean;
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
    rank_id: number;
    rank_name: string;
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
    pool_department_ids: number[];
    max_home_days: number;
    sync_sea_service: boolean;
    sync_training_to_employee_training?: boolean;
    notifications_enabled: boolean;
    notification_recipient_user_ids: number[];
    alert_signoff_overdue: boolean;
    alert_signoff_no_relief: boolean;
    alert_relief_not_ready: boolean;
    alert_current_manning_gap: boolean;
    alert_projected_manning_gap: boolean;
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
    rank_id: string;
    employee_id: string;
    planned_join_date: string;
    planned_leave_date: string;
    notes: string;
    relieves_crew_assignment_id: string;
};

export type CrewDragData = {
    type: 'crew';
    employeeId: number;
    employeeName: string;
    rankId: number;
    rankName: string;
};
export type RowDropData = { type: 'row'; vesselId: number; rankId: number };
