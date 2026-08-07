export type ProjectedManningHorizon = 30 | 60 | 90;

export type ProjectedManningStatus =
    | 'covered'
    | 'covered_by_incoming'
    | 'current_gap'
    | 'future_gap'
    | 'overlap';

export type ProjectedManningOption = {
    id: number;
    name: string;
};

export type ProjectedManningFilters = {
    horizon: ProjectedManningHorizon;
    vessel_id: number | null;
    rank_id: number | null;
};

export type ProjectedManningSummary = {
    positions: number;
    current_gap_positions: number;
    future_gap_positions: number;
    covered_positions: number;
    overlap_positions: number;
    total_projected_shortfall_days: number;
};

export type ProjectedManningEvent = {
    date: string;
    type: 'join' | 'signoff';
    delta: number;
    employee_id: number | null;
    crew_assignment_id: number | null;
    crew_planning_assignment_id: number | null;
    is_relief: boolean;
};

export type ProjectedManningPeriod = {
    from: string;
    to: string;
    projected_count: number;
    gap: number;
    excess: number;
};

export type ProjectedManningItem = {
    vessel_id: number;
    vessel_name: string;
    rank_id: number;
    rank_name: string;
    required_count: number;
    actual_onboard_at_start: number;
    projected_count_at_start: number;
    starting_count: number;
    minimum_projected_count: number;
    maximum_projected_count: number;
    current_gap: number;
    maximum_gap: number;
    next_gap_date: string | null;
    status: ProjectedManningStatus;
    status_label: string;
    has_overlap: boolean;
    has_open_ended_onboard: boolean;
    events: ProjectedManningEvent[];
    periods: ProjectedManningPeriod[];
};

export type ProjectedManningPagePermissions = {
    view: boolean;
    plan_crew: boolean;
};

export type ProjectedManningPageProps = {
    from: string;
    to: string;
    company_timezone: string;
    summary: ProjectedManningSummary;
    items: ProjectedManningItem[];
    filters: ProjectedManningFilters;
    horizons: ProjectedManningHorizon[];
    vessels: ProjectedManningOption[];
    ranks: ProjectedManningOption[];
    can: ProjectedManningPagePermissions;
};
