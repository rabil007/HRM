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
    start_at?: string | null;
    end_at?: string | null;
    planned_start_at?: string | null;
    planned_end_at?: string | null;
    status: string;
    days: number | null;
    days_label: string;
    remarks?: string | null;
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

export type PhaseTimelineEntry = {
    id: number;
    sequence: number;
    occurrence: number;
    phase_code: string;
    phase_label: string;
    status: string;
    planned_start_at: string | null;
    planned_end_at: string | null;
    actual_start_at: string | null;
    actual_end_at: string | null;
    days: number | null;
    days_label: string;
    remarks: string | null;
    details: Record<string, unknown> | null;
    is_legacy: boolean;
};

export type TrainingHistoryEntry = {
    occurrence: number;
    sequence: number;
    status: string;
    provider: string | null;
    course: string | null;
    summary: string | null;
    planned_start_at: string | null;
    planned_end_at: string | null;
    actual_start_at: string | null;
    actual_end_at: string | null;
    remarks: string | null;
    employee_training_linked: boolean;
    employee_training: {
        id: number;
        course_id: number | null;
        course_name: string | null;
    } | null;
};

export type AccommodationStaySummary = {
    id: number;
    stay_type: string;
    stay_type_label: string;
    accommodation_status: string;
    accommodation_status_label: string;
    hotel_name: string | null;
    room_type_name: string | null;
    check_in_date: string | null;
    check_out_date: string | null;
    is_open: boolean;
    stay_days: number | null;
    started_from_phase_code?: string | null;
    started_from_phase_label?: string | null;
};

export type LinkedAssignmentSummary = {
    id: number;
    assignment_no: string;
    direction: string;
    source: string | null;
    source_label: string;
    status: string;
    status_label: string;
    vessel: ReportOption | null;
    rank: ReportOption | null;
    client: ReportOption | null;
    started_at: string | null;
    closed_at: string | null;
    current_phase_code: string | null;
};

export type TourSummary = {
    tour_of_duty_days: number | null;
    planned_signoff_source: string | null;
    planned_signoff_source_label: string | null;
    planned_signoff_override_reason: string | null;
    days_onboard: number | null;
    current_duty_day: number | null;
    remaining_tour_days: number | null;
    tour_progress_percent: number | null;
    tour_progress_display_percent: number | null;
    tour_status: string | null;
    tour_status_label: string | null;
    tour_status_severity: string | null;
};

export type PayrollDayPeriod = {
    from: string;
    to: string;
    days: number;
};

export type PayrollDaySummary = {
    periods: PayrollDayPeriod[];
    total_days: number;
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
    status: string;
    status_label: string;
    current_phase: {
        code: string;
        label: string;
        status: string;
    } | null;
    source: string | null;
    source_label: string;
    remarks: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    planned_travel_in: string | null;
    planned_travel_in_origin?: string | null;
    planned_travel_in_origin_label?: string | null;
    planned_arrival?: string | null;
    planned_arrival_origin?: string | null;
    planned_arrival_origin_label?: string | null;
    actual_arrival?: string | null;
    actual_arrival_at?: string | null;
    actual_arrival_origin?: string | null;
    actual_arrival_origin_label?: string | null;
    planned_join: string | null;
    planned_join_origin?: string | null;
    planned_join_origin_label?: string | null;
    planned_signoff: string | null;
    planned_signoff_origin?: string | null;
    planned_signoff_origin_label?: string | null;
    planned_travel_home: string | null;
    planned_travel_home_origin?: string | null;
    planned_travel_home_origin_label?: string | null;
    has_legacy_phases: boolean;
    phase_timeline?: PhaseTimelineEntry[];
    pre_mobilisation: FlattenedPhaseSummary;
    travel_in: FlattenedPhaseSummary;
    join_standby: PhaseSummary;
    training: PhaseSummary & {
        details: string[];
        history?: TrainingHistoryEntry[];
    };
    ready_to_join: FlattenedPhaseSummary;
    on_vessel: PhaseSummary & {
        actual_join: string | null;
        actual_join_at?: string | null;
        actual_disembarkation: string | null;
        actual_disembarkation_at?: string | null;
        to: string | null;
    };
    demob_standby: FlattenedPhaseSummary;
    home_redeploy: FlattenedPhaseSummary & {
        actual_return_home_at?: string | null;
    };
    assignment_started: string | null;
    assignment_started_at?: string | null;
    assignment_closed: string | null;
    assignment_closed_at?: string | null;
    total_assignment_days: number | null;
    total_assignment_days_label: string;
    tour?: TourSummary;
    accommodation_stays?: AccommodationStaySummary[];
    linked_assignments?: {
        previous: LinkedAssignmentSummary | null;
        next: LinkedAssignmentSummary[];
        relationship: string | null;
        relationship_label: string | null;
    };
    payroll_days: {
        sign_on_standby: PayrollDaySummary;
        onsite: PayrollDaySummary;
        sign_off_standby: PayrollDaySummary;
        total_days: number;
    };
    needs_attention: boolean;
    warnings: string[];
    warning_details?: Array<{
        code: string;
        severity: string;
        label: string;
        message: string;
        date: string | null;
    }>;
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
    source: string;
    needs_attention: string;
    planned_arrival_from: string;
    planned_arrival_to: string;
    planned_join_from: string;
    planned_join_to: string;
    planned_signoff_from: string;
    planned_signoff_to: string;
    actual_arrival_from: string;
    actual_arrival_to: string;
    actual_join_from: string;
    actual_join_to: string;
    actual_disembarkation_from: string;
    actual_disembarkation_to: string;
    assignment_started_from: string;
    assignment_started_to: string;
    assignment_closed_from: string;
    assignment_closed_to: string;
    hotel_id: string;
    accommodation_status: string;
    stay_type: string;
    tour_status: string;
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
        sources: SelectOption[];
        hotels: ReportOption[];
        accommodation_statuses: SelectOption[];
        stay_types: SelectOption[];
        tour_statuses: SelectOption[];
    };
    can: {
        export: boolean;
    };
};
