export type CrewReadinessFocus =
    | ''
    | 'ready'
    | 'attention'
    | 'not_ready'
    | 'joining_7'
    | 'no_checks';

export interface CrewReadinessSummary {
    upcoming_crew: number;
    ready: number;
    attention: number;
    not_ready: number;
    joining_7: number;
    no_checks: number;
}

export interface CrewReadinessCheck {
    code: string;
    severity: 'ok' | 'warning' | 'critical' | string;
    label: string;
    message: string;
    document_type_id: number | null;
}

export interface CrewReadinessDetails {
    applies: boolean;
    status: 'ready' | 'attention' | 'not_ready';
    status_label: string;
    checks_clear: number;
    checks_total: number;
    has_configured_checks: boolean;
    advisory_note: string;
    problems: CrewReadinessCheck[];
    checks: CrewReadinessCheck[];
    documents_href: string | null;
}

export interface CrewReadinessRow {
    id: string;
    source_type: 'planning' | 'assignment';
    source_label: string;
    stage_label: string;
    phase_code: string | null;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        href: string | null;
    };
    vessel: {
        id: number;
        name: string;
        href: string | null;
    } | null;
    position: {
        id: number;
        title: string;
    } | null;
    expected_arrival_date: string | null;
    expected_join_date: string | null;
    expected_signoff_date: string | null;
    days_until_join: number | null;
    is_overdue: boolean;
    is_joining_soon: boolean;
    readiness: CrewReadinessDetails;
    planning_assignment_id: number | null;
    crew_assignment_id: number | null;
    can_view_plan: boolean;
    plan_href: string | null;
    can_view_assignment: boolean;
    assignment_href: string | null;
    can_start_mobilisation: boolean;
    start_mobilisation_href: string | null;
}

export interface CrewReadinessFilters {
    search: string | null;
    vessel_id: number | null;
    position_id: number | null;
    readiness_status: string;
    source: string;
    window: string;
    focus: CrewReadinessFocus;
    per_page: number;
    page: number;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface OptionItem {
    id: number;
    name?: string;
    title?: string;
}

export interface ValueOption {
    value: string;
    label: string;
}

export interface CrewReadinessFilterOptions {
    vessels: OptionItem[];
    positions: OptionItem[];
    windows: ValueOption[];
    sources: ValueOption[];
    statuses: ValueOption[];
}

export interface CrewReadinessPayload {
    rows: CrewReadinessRow[];
    pagination: PaginationMeta;
    summary: CrewReadinessSummary;
    filters: CrewReadinessFilters;
    filter_options: CrewReadinessFilterOptions;
    has_active_query: boolean;
}
