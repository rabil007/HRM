import type { PaginationMeta } from '@/types/pagination';

export type ReportOption = {
    id: number;
    name: string;
};

export type SelectOption = {
    value: string;
    label: string;
};

export type PhasePeriod = {
    sequence: number;
    start: string | null;
    end: string | null;
    status: string;
    days: number | null;
    days_label: string;
};

export type PhaseSummary = {
    periods: PhasePeriod[];
    total_days: number | null;
    total_days_label: string;
};

export type FlattenedPhaseSummary = PhaseSummary & {
    from: string | null;
    to: string | null;
};

export type CrewMovementHistoryRow = {
    id: number;
    assignment_no: string;
    employee: {
        id: number | null;
        employee_no: string | null;
        name: string | null;
    };
    rank: ReportOption | null;
    vessel: ReportOption | null;
    client: ReportOption | null;
    visa_type: ReportOption | null;
    status: string;
    status_label: string;
    current_phase: {
        code: string;
        label: string;
        status: string;
    } | null;
    source: string | null;
    source_label: string;
    planned_travel_in: string | null;
    planned_join: string | null;
    planned_signoff: string | null;
    planned_travel_home: string | null;
    pre_mobilisation: FlattenedPhaseSummary;
    travel_in: FlattenedPhaseSummary;
    join_standby: PhaseSummary;
    training: PhaseSummary & { details: string[] };
    ready_to_join: FlattenedPhaseSummary;
    on_vessel: PhaseSummary & {
        actual_join: string | null;
        actual_disembarkation: string | null;
        to: string | null;
    };
    demob_standby: FlattenedPhaseSummary;
    home_redeploy: FlattenedPhaseSummary;
    assignment_started: string | null;
    assignment_closed: string | null;
    total_assignment_days: number | null;
    total_assignment_days_label: string;
    remarks: string | null;
    needs_attention: boolean;
    warnings: string[];
    has_corrections: boolean;
    correction_count: number;
    last_corrected_at: string | null;
    has_pending_corrections: boolean;
    company_timezone: string;
};

export type CrewMovementHistoryFilters = {
    search: string;
    status: string;
    current_phase: string;
    vessel_id: string;
    rank_id: string;
    client_id: string;
    visa_type_id: string;
    source: string;
    needs_attention: string;
    planned_join_from: string;
    planned_join_to: string;
    actual_join_from: string;
    actual_join_to: string;
    actual_disembarkation_from: string;
    actual_disembarkation_to: string;
    assignment_started_from: string;
    assignment_started_to: string;
    assignment_closed_from: string;
    assignment_closed_to: string;
    has_approved_corrections: string;
    has_pending_corrections: string;
    sort: string;
    direction: string;
};

export type CrewMovementHistoryProps = {
    assignments: CrewMovementHistoryRow[];
    pagination: PaginationMeta;
    summary: {
        total: number;
        draft: number;
        active: number;
        completed: number;
        cancelled: number;
        on_vessel: number;
        needs_attention: number;
    };
    filters: CrewMovementHistoryFilters;
    filter_options: {
        statuses: SelectOption[];
        phases: SelectOption[];
        vessels: ReportOption[];
        ranks: ReportOption[];
        clients: ReportOption[];
        visa_types: ReportOption[];
        sources: SelectOption[];
    };
    can: {
        export: boolean;
    };
};
