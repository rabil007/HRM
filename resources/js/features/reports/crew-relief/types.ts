export interface CrewReliefEmployee {
    id: number;
    name: string;
    employee_no: string | null;
    photo_url?: string | null;
    href?: string | null;
}

export interface CrewReliefRank {
    id: number;
    name: string;
}

export interface CrewReliefVessel {
    id: number;
    name: string;
    href?: string | null;
}

export interface CrewReliefClient {
    id: number;
    name: string;
}

export interface CrewReliefNextAssignment {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    href?: string | null;
}

export interface CrewReliefAttention {
    level: 'critical' | 'warning' | 'healthy' | 'neutral';
    badge: string;
    reason: string;
}

export interface CrewReliefRow {
    id: number;
    assignment_no: string;
    source_href: string | null;
    employee: CrewReliefEmployee | null;
    rank: CrewReliefRank | null;
    vessel: CrewReliefVessel | null;
    client: CrewReliefClient | null;
    joined_date: string | null;
    days_onboard: number | null;
    planned_signoff_at: string | null;
    days_to_signoff: number | null;
    days_to_signoff_label: string | null;
    relief_employee: CrewReliefEmployee | null;
    relief_status: string;
    relief_phase_code: string | null;
    relief_planned_join: string | null;
    readiness:
        | 'ready'
        | 'in_progress'
        | 'not_assigned'
        | 'at_risk'
        | 'joined'
        | 'restricted';
    readiness_label: string;
    next_assignment: CrewReliefNextAssignment | null;
    attention: CrewReliefAttention;
}

export interface CrewReliefSummary {
    signing_off_next_7_days: number;
    no_relief_assigned: number;
    relief_not_ready: number;
    overdue_signoffs: number;
}

export interface CrewReliefFilters {
    search: string;
    vessel_id: string;
    client_id: string;
    rank_id: string;
    planned_signoff_from: string;
    planned_signoff_to: string;
    readiness: string;
    attention: string;
    preset: string;
    per_page: number;
}

export interface CrewReliefFilterOptions {
    vessels: Array<{ id: number; name: string; client_id: number | null }>;
    clients: Array<{ id: number; name: string }>;
    ranks: Array<{ id: number; name: string }>;
    readiness_options: Array<{ value: string; label: string }>;
    attention_options: Array<{ value: string; label: string }>;
}

export interface CrewReliefPagePermissions {
    export: boolean;
    view_assignments: boolean;
    view_employees: boolean;
    view_vessels: boolean;
}

export interface CrewReliefPagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface CrewReliefProps {
    rows: CrewReliefRow[];
    pagination: CrewReliefPagination;
    summary: CrewReliefSummary;
    filters: CrewReliefFilters;
    filter_options: CrewReliefFilterOptions;
    can: CrewReliefPagePermissions;
}
