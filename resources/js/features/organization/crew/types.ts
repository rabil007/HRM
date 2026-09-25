import type {
    CrewMovementCorrectionFieldValue,
    CrewMovementCorrectionListItem,
} from '@/features/organization/crew-movement-corrections/types';

export type CrewTourProgressFields = {
    tour_of_duty_days: number | null;
    planned_signoff_source: string | null;
    planned_signoff_source_label: string | null;
    days_onboard: number | null;
    current_duty_day: number | null;
    remaining_tour_days: number | null;
    tour_progress_percent: number | null;
    tour_progress_display_percent: number | null;
    tour_status: string | null;
    tour_status_label: string | null;
    tour_status_severity: string | null;
};

export type ActiveOnVesselAssignment = {
    assignment_id: number;
    assignment_no: string;
    employee_id: number;
    employee_name: string;
    vessel_id: number | null;
    vessel_name: string | null;
    phase_id: number;
    actual_start_at: string | null;
    actual_start_display: string | null;
    status: string;
    can_transfer?: boolean;
};

export type CrewPreJoinAccommodationContext = {
    status: 'open_hotel' | 'no_accommodation' | 'missing';
    stay_id: number | null;
    hotel_id: number | null;
    hotel_name: string | null;
    room_type_id: number | null;
    room_type_name: string | null;
    check_in_date: string | null;
    check_out_date: string | null;
    stay_days: number | null;
    warning: string | null;
};

export type CrewPostSignoffAccommodationContext =
    CrewPreJoinAccommodationContext;

export type CrewAccommodationSummaryItem = {
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
};

export type CrewMovementContext = {
    assignment_id: number;
    assignment_no: string;
    employee_id: number | null;
    employee_name: string | null;
    employee_no: string | null;
    current_phase_code: string | null;
    current_phase_label: string | null;
    current_phase_started_at: string | null;
    days_in_phase: number | null;
    days_onboard: number | null;
    days_in_training: number | null;
    vessel_id: number | null;
    vessel_name: string | null;
    rank_id: number | null;
    rank_name: string | null;
    client_id: number | null;
    client_name: string | null;
    planned_join_at: string | null;
    planned_arrival_at?: string | null;
    planned_signoff_at: string | null;
    planned_travel_at: string | null;
    actual_arrival_at?: string | null;
    actual_join_at: string | null;
    actual_disembarkation_at: string | null;
    training_provider: string | null;
    training_course: string | null;
    training_course_id?: number | null;
    sync_training_enabled?: boolean;
    training_started_at: string | null;
    training_expected_completion_at: string | null;
    company_timezone: string;
    pre_join_accommodation?: CrewPreJoinAccommodationContext;
    post_signoff_accommodation?: CrewPostSignoffAccommodationContext;
    active_on_vessel_elsewhere?: ActiveOnVesselAssignment | null;
} & CrewTourProgressFields;

export type CrewReliefEmployee = {
    id: number;
    name: string;
    employee_no: string | null;
};

export type CrewReliefPhase = {
    code: string;
    label: string;
    status: string;
};

export type CrewReliefFields = {
    relief_status: string | null;
    relief_status_label: string | null;
    relief_action_label: string | null;
    relief_risk: string | null;
    relief_risk_label: string | null;
    relief_employee: CrewReliefEmployee | null;
    relief_planning_assignment_id: number | null;
    relief_crew_assignment_id: number | null;
    relief_planned_join_date: string | null;
    relief_phase: CrewReliefPhase | null;
    relief_phase_code: string | null;
    relief_phase_label: string | null;
    relief_phase_status: string | null;
    source_planned_signoff_date: string | null;
    days_until_signoff: number | null;
};

export type CrewRelievesContext = {
    planning_assignment_id: number;
    source_assignment_id: number;
    source_assignment_no: string;
    source_employee: CrewReliefEmployee | null;
    source_vessel: { id: number; name: string } | null;
    source_rank: { id: number; name: string } | null;
    source_planned_signoff_at: string | null;
};

export interface CrewAssignmentListItem
    extends CrewTourProgressFields, CrewReliefFields {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        image?: string | null;
    } | null;
    rank: {
        id: number;
        name: string;
    } | null;
    vessel: {
        id: number;
        name: string;
    } | null;
    client: {
        id: number;
        name: string;
    } | null;
    current_phase: {
        code: string;
        label: string;
        status: string;
        started_at?: string | null;
    } | null;
    days_in_phase: number | null;
    planned_join_at: string | null;
    planned_arrival_at?: string | null;
    planned_signoff_at: string | null;
    planned_travel_at?: string | null;
    actual_arrival_at?: string | null;
    actual_join_at?: string | null;
    actual_disembarkation_at?: string | null;
    created_at: string | null;
    company_timezone?: string;
    warnings: CrewAssignmentWarning[];
    available_actions: string[];
    mobilisation_readiness: CrewMobilisationReadiness | null;
    recommended_action?: CrewRecommendedAction | null;
    movement_context: CrewMovementContext;
}

export interface CrewAssignmentDetail
    extends CrewTourProgressFields, CrewReliefFields {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    is_editable: boolean;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        image?: string | null;
    } | null;
    rank: {
        id: number;
        name: string;
        max_tour_of_duty_days?: number | null;
    } | null;
    vessel: {
        id: number;
        name: string;
    } | null;
    client: {
        id: number;
        name: string;
    } | null;
    current_phase: {
        id: number;
        code: string;
        label: string;
        status: string;
        status_label: string;
        started_at?: string | null;
    } | null;
    days_in_phase: number | null;
    days_in_training?: number | null;
    planned_join_at: string | null;
    planned_arrival_at?: string | null;
    planned_signoff_at: string | null;
    planned_travel_at: string | null;
    actual_arrival_at?: string | null;
    actual_join_at: string | null;
    actual_disembarkation_at: string | null;
    started_at: string | null;
    closed_at: string | null;
    source: string | null;
    remarks: string | null;
    created_at: string | null;
    updated_at: string | null;
    company_timezone?: string;
    can_apply_tour_of_duty?: boolean;
    current_rank_tour_days?: number | null;
    suggested_planned_signoff_at?: string | null;
    phase_timeline: PhaseTimelineItem[];
    warnings: CrewAssignmentWarning[];
    available_actions: string[];
    mobilisation_readiness: CrewMobilisationReadiness | null;
    recommended_action: CrewRecommendedAction | null;
    planning_assignment_id: number | null;
    relieves: CrewRelievesContext | null;
    previous_assignment: {
        id: number;
        assignment_no: string;
        status: string;
        status_label: string;
        source: string | null;
        vessel_name: string | null;
        closed_at: string | null;
    } | null;
    next_assignments: Array<{
        id: number;
        assignment_no: string;
        status: string;
        status_label: string;
        source: string | null;
        vessel_name: string | null;
        started_at: string | null;
    }>;
    accommodation?: CrewAccommodationSummaryItem[];
    movement_context: CrewMovementContext;
}

export interface PhaseTimelineItem {
    id: number;
    sequence: number;
    phase_code: string;
    phase_label: string;
    status: string;
    status_label: string;
    planned_start_at: string | null;
    planned_end_at: string | null;
    actual_start_at: string | null;
    actual_end_at: string | null;
    details: Record<string, unknown> | null;
    remarks: string | null;
    has_pending_correction: boolean;
    has_approved_correction: boolean;
    own_pending_correction_id?: number | null;
    can_cancel_pending?: boolean;
    employee_training_id?: number | null;
}

export interface CrewMobilisationReadinessCheck {
    code: string;
    severity: string;
    label: string;
    message: string;
    document_type_id: number | null;
}

export interface CrewMobilisationReadiness {
    applies: boolean;
    status: 'ready' | 'attention' | 'not_ready' | string;
    status_label: string;
    checks_clear: number;
    checks_total: number;
    advisory_note: string;
    problems: CrewMobilisationReadinessCheck[];
    checks?: CrewMobilisationReadinessCheck[];
    documents_href: string | null;
}

export interface CrewRecommendedAction {
    type: string;
    action: string | null;
    label: string;
    reason: string;
    href: string | null;
    anyway_action: string | null;
    anyway_label: string | null;
}

export interface CrewAssignmentWarning {
    code: string;
    severity: 'info' | 'warning' | 'critical';
    label: string;
    message: string;
    date: string | null;
}

export interface CrewAssignmentFormData {
    employee_id: number | null;
    rank_id: number | null;
    client_id: number | null;
    vessel_id: number | null;
    planned_join_at: string;
    planned_arrival_at?: string | null;
    remarks: string;
}

export interface CrewAssignmentCreateFormData {
    employee_id: number | null;
    rank_id: number | null;
    client_id: number | null;
    vessel_id: number | null;
    planned_join_at: string;
    planned_signoff_at?: string;
    planned_arrival_at?: string | null;
    relieves_crew_assignment_id?: number | null;
    submission_intent: 'start' | 'draft' | 'plan';
    remarks: string;
}

export type BulkAddCrewRow = {
    employee_id: number | null;
    rank_id: number | null;
    planned_arrival_at?: string | null;
};

export interface BulkAddCrewFormData {
    client_id: number | null;
    vessel_id: number | null;
    planned_join_at: string;
    planned_arrival_at?: string | null;
    remarks: string;
    crew: BulkAddCrewRow[];
}

export interface CrewAssignmentFormOptions {
    employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
        rank_id: number | null;
        image?: string | null;
        nationality_name?: string | null;
    }>;
    ranks: Array<{
        id: number;
        name: string;
        max_tour_of_duty_days?: number | null;
        resolved_tour_of_duty_days?: number | null;
    }>;
    vessels: Array<{
        id: number;
        name: string;
        client_id?: number | null;
        is_active?: boolean;
    }>;
    clients: Array<{ id: number; name: string }>;
    courses: Array<{ id: number; name: string }>;
    hotels?: Array<{ id: number; name: string }>;
    room_types?: Array<{ id: number; name: string; hotel_id: number | null }>;
    /** Company IANA timezone for consistent operational date display. */
    company_timezone?: string;
}

export interface EmployeeOperationalStatus {
    status: string;
    label: string;
    current_phase: string | null;
    current_vessel: string | null;
    assignment_id: number | null;
    assignment_no: string | null;
    since: string | null;
    days_in_phase: number | null;
    planned_next_date: string | null;
    warning: string | null;
    in_home_days: number | null;
    vessel_name: string | null;
    days_at_home?: number | null;
    availability_status?: CurrentCrewHomeAvailabilityStatus | null;
    availability_label?: string | null;
    availability_detail?: string | null;
    /** Always present. True when the employee has an Active assignment, including Active P0. False for Draft P0, completed, or no assignment. */
    has_active_assignment: boolean;
}

export interface CrewAssignmentCreateFormOptions extends CrewAssignmentFormOptions {
    active_on_vessel_by_employee?: Record<string, ActiveOnVesselAssignment>;
    employee_status_by_employee?: Record<string, EmployeeOperationalStatus>;
    /** Company IANA timezone string (e.g. 'Asia/Dubai'). Used to render all operational phase timestamps consistently. */
    company_timezone?: string;
    max_home_days?: number;
}

export type CrewPlanningStartContext = {
    planning_assignment_id: number;
    employee_id: number | null;
    employee_name: string | null;
    rank_id: number | null;
    rank_name: string | null;
    vessel_id: number | null;
    vessel_name: string | null;
    client_id: number | null;
    client_name: string | null;
    planned_join_at: string | null;
    planned_signoff_at?: string | null;
    planned_arrival_at?: string | null;
    remarks: string | null;
};

export type CrewPlanningBackQuery = Record<string, string | number>;

export interface CrewAssignmentSummary {
    total: number;
    needs_attention: number;
    by_phase: Record<string, number>;
    pre_join_hotel: number;
    crew_on_site: number;
    post_signoff_hotel: number;
    on_home: number;
    on_home_over_limit: number;
    max_home_days: number;
}

export type CurrentCrewHomeAvailabilityStatus =
    | 'within_limit'
    | 'near_limit'
    | 'over_limit';

export interface CurrentCrewHomeRow {
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
        image?: string | null;
    };
    rank: {
        id: number;
        name: string;
    } | null;
    last_vessel: {
        id: number;
        name: string;
    } | null;
    home_since: string | null;
    days_at_home: number | null;
    max_home_days: number;
    availability_status: CurrentCrewHomeAvailabilityStatus;
    availability_label: string;
    availability_detail: string | null;
    latest_assignment: {
        id: number;
        assignment_no: string;
        status: string;
        status_label: string;
    } | null;
    can: {
        view_employee: boolean;
        view_assignment: boolean;
        start_assignment: boolean;
    };
}

export type CrewFilterOption = {
    value: string;
    label: string;
};

export interface CrewAssignmentFilterOptions {
    vessels: Array<{
        id: number;
        name: string;
        client_id?: number | null;
        client_ids?: number[];
    }>;
    ranks: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
    }>;
    relief_statuses?: CrewFilterOption[];
    relief_risks?: CrewFilterOption[];
}

export type CurrentCrewView =
    | 'crew'
    | 'pre_join_hotel'
    | 'vessel'
    | 'post_signoff_hotel'
    | 'on_home';

export interface CurrentCrewVesselRow {
    id: number;
    name: string;
    client_name: string | null;
    onboard_count: number;
    required_count: number;
    gap: number;
    coverage_label: string;
    crew: CrewAssignmentListItem[];
}

export interface CrewAssignmentFilters {
    phase: string;
    status: string;
    vessel_id: string;
    rank_id: string;
    client_id: string;
    employee_id: string;
    planned_join_from: string;
    planned_join_to: string;
    planned_signoff_from: string;
    planned_signoff_to: string;
    movement_attention: boolean;
    include_completed: boolean;
    tour_status: string;
    relief_status: string;
    relief_risk: string;
    relief_not_ready: boolean;
    signoff_within_14_no_relief: boolean;
}

export interface CrewAssignmentPagePermissions {
    view: boolean;
    create: boolean;
    plan?: boolean;
    create_historical?: boolean;
    start: boolean;
    update: boolean;
    perform_movement: boolean;
    cancel: boolean;
    void: boolean;
    view_audit: boolean;
    request_correction: boolean;
    view_corrections: boolean;
    approve_corrections: boolean;
    override_corrections: boolean;
    view_documents: boolean;
    view_training: boolean;
    view_planning: boolean;
    view_employee: boolean;
    delete_sea_service?: boolean;
    delete_training?: boolean;
}

export type VoidAssignmentImpactItem = {
    id: number;
    assignment_no: string;
    employee_name: string;
    current_phase?: {
        code: string;
        label: string;
        status?: string | null;
    } | null;
    sea_service_count: number;
    training_count: number;
    blockers: Array<{ code: string; message: string }>;
    has_protected_blockers: boolean;
    has_sea_service: boolean;
};

export type VoidImpactPreview = {
    total_assignments: number;
    total_sea_service_records: number;
    total_training_records: number;
    can_delete_sea_service: boolean;
    can_delete_training: boolean;
    has_sea_service: boolean;
    has_training: boolean;
    has_protected_blockers: boolean;
    blocked_assignment_nos: string[];
    assignments: VoidAssignmentImpactItem[];
};

export interface CorrectablePhase {
    id: number;
    phase_code: string;
    phase_label: string;
    status: string;
    status_label: string;
    actual_start_at?: string | null;
    actual_end_at?: string | null;
    remarks?: string | null;
    details?: Record<string, unknown> | null;
    is_legacy?: boolean;
    legacy_context_label?: string | null;
    allowed_fields: string[];
    has_pending_correction: boolean;
    current_values: Record<string, CrewMovementCorrectionFieldValue>;
}

export interface CrewCorrectionRequestContext {
    correctable_phases: CorrectablePhase[];
}

export interface CorrectionsSummary {
    pending: CrewMovementCorrectionListItem[];
    history: CrewMovementCorrectionListItem[];
    pending_count: number;
    approved_count: number;
    correctable_phases: CorrectablePhase[];
}

export type CrewMovementAction =
    | 'approve_mobilisation'
    | 'record_arrival'
    | 'start_join_standby'
    | 'send_to_training'
    | 'complete_training'
    | 'mark_ready'
    | 'join_vessel'
    | 'plan_signoff'
    | 'confirm_disembarkation'
    | 'start_demob_standby'
    | 'travel_home'
    | 'transfer_vessel'
    | 'redeploy'
    | 'close_assignment'
    | 'cancel_assignment'
    | 'correct_movement';

export const CREW_MOVEMENT_ACTION_LABELS: Record<CrewMovementAction, string> = {
    approve_mobilisation: 'Start Assignment',
    record_arrival: 'Record Arrival',
    start_join_standby: 'Start Join Standby',
    send_to_training: 'Send to Training',
    complete_training: 'Complete Training',
    mark_ready: 'Mark Ready',
    join_vessel: 'Join Vessel',
    plan_signoff: 'Plan Sign-off',
    confirm_disembarkation: 'Confirm Disembarkation',
    start_demob_standby: 'Start Demobilisation Standby',
    travel_home: 'Travel Home',
    transfer_vessel: 'Transfer Vessel',
    redeploy: 'Redeploy',
    close_assignment: 'Close Assignment',
    cancel_assignment: 'Cancel Assignment',
    correct_movement: 'Correct Movement',
};

export const CREW_PHASE_LABELS: Record<string, string> = {
    p0: 'Pre-Mobilisation',
    p1: 'Travel In',
    p2a: 'Join Standby',
    p2b: 'Training',
    p3: 'Ready to Join',
    p4: 'On Vessel',
    p5: 'Demobilisation Standby',
    p6: 'Home / Redeployment',
};

export interface CrewMovementActionFormData {
    action: CrewMovementAction;
    occurred_at: string;
    next_phase: string;
    starting_phase: string;
    provider: string;
    course: string;
    course_id?: number | null;
    sync_training_to_employee_training?: boolean;
    planned_start_at: string;
    planned_end_at: string;
    remarks: string;
    vessel_id: number | null;
    rank_id: number | null;
    client_id: number | null;
    planned_signoff_at: string;
    planned_travel_at: string;
    planned_arrival_at?: string | null;
    reason: string;
    planned_signoff_choice:
        | 'tour_of_duty'
        | 'existing_plan'
        | 'manual_override';
    planned_signoff_override_reason: string;
    completion_intent: 'close' | 'redeploy' | '';
    accommodation_status: 'hotel' | 'no_accommodation' | '';
    hotel_id: number | null;
    room_type_id: number | null;
    check_in_date: string;
    check_out_date: string;
    source_check_out_date: string;
    no_hotel_accommodation: boolean;
}

export interface HistoricalPreviewCheck {
    code: string;
    passed: boolean;
    message: string;
}

export interface HistoricalPreviewTimelineItem {
    phase_code: string;
    phase_label: string;
    start: string;
    end: string | null;
    end_display?: string | null;
    is_open?: boolean;
    duration_days: number | null;
}

export interface HistoricalInferredState {
    phase_code: string;
    label: string;
    event_key: string;
    event_label: string;
    event_at: string;
    assignment_status: string;
    is_open: boolean;
}

export interface HistoricalLastMovement {
    event_key: string;
    event_label: string;
    event_at: string;
    display: string;
}

export interface HistoricalSeaServiceImpact {
    status:
        | 'will_create'
        | 'will_link'
        | 'warning'
        | 'conflict'
        | 'disabled'
        | 'not_applicable';
    days: number;
    months: number;
    start_date: string | null;
    end_date: string | null;
    vessel_id: number;
    vessel_name: string;
    existing_id?: number | null;
    message: string;
}

export interface HistoricalCrewAssignmentPreviewData {
    valid: boolean;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
    };
    vessel: {
        id: number;
        name: string;
    };
    rank: {
        id: number;
        name: string;
    };
    client: {
        id: number;
        name: string;
    } | null;
    summary: {
        joined_vessel_at: string | null;
        disembarked_at: string | null;
        sea_service_days: number | null;
        remarks: string | null;
        assignment_status?: string | null;
        is_open?: boolean | null;
    };
    timeline: HistoricalPreviewTimelineItem[];
    checks: HistoricalPreviewCheck[];
    warnings: string[];
    sea_service: HistoricalSeaServiceImpact;
    inferred_state?: HistoricalInferredState | null;
    last_movement?: HistoricalLastMovement | null;
    errors?: string[];
}

export interface HistoricalCrewAssignmentFormData {
    employee_id: string | number;
    vessel_id: string | number;
    rank_id: string | number;
    client_id: string | number;
    mobilisation_at?: string;
    join_standby_at?: string;
    training_started_at?: string;
    training_ended_at?: string;
    joined_vessel_at?: string;
    disembarked_at?: string;
    travel_home_at?: string;
    remarks?: string;
}

export interface HistoricalEmployeeOption {
    id: number;
    name: string;
    employee_no: string | null;
    rank_id: number | null;
    status: string;
}

export interface HistoricalFormOptions {
    employees: HistoricalEmployeeOption[];
    ranks: Array<{
        id: number;
        name: string;
        is_active?: boolean;
        [key: string]: unknown;
    }>;
    vessels: Array<{
        id: number;
        name: string;
        client_id?: number | null;
        is_active?: boolean;
        [key: string]: unknown;
    }>;
    clients: Array<{
        id: number;
        name: string;
        is_active?: boolean;
        [key: string]: unknown;
    }>;
    company_timezone: string;
}

export type HistoricalImportRowStatus = 'ready' | 'warning' | 'blocked';

export interface HistoricalImportPreviewRow {
    row: number;
    status: HistoricalImportRowStatus;
    employee: {
        id: number | null;
        employee_no: string | null;
        name: string | null;
        label: string;
    };
    vessel: {
        id: number | null;
        name: string | null;
    };
    rank: {
        id: number | null;
        name: string | null;
    };
    client: {
        id: number | null;
        name: string | null;
    } | null;
    joined_vessel_at: string | null;
    disembarked_at: string | null;
    last_movement: HistoricalLastMovement | null;
    inferred_state: HistoricalInferredState | null;
    is_open: boolean;
    errors: string[];
    error_fields: Record<string, string>;
    warnings: string[];
    workbook_messages: string[];
    checks: HistoricalPreviewCheck[];
    timeline: HistoricalPreviewTimelineItem[];
    sea_service: HistoricalSeaServiceImpact | null;
    summary: {
        joined_vessel_at: string | null;
        disembarked_at: string | null;
        sea_service_days: number | null;
        remarks: string | null;
        assignment_status?: string | null;
        is_open?: boolean | null;
    };
    conflicting_assignment: {
        id: number;
        assignment_no: string;
        started_at: string | null;
        closed_at: string | null;
    } | null;
}

export interface HistoricalImportBatchSummary {
    id: number;
    batch_no: string;
    original_filename: string;
    status: string;
    status_label: string;
    total_rows: number;
    ready_rows: number;
    warning_rows: number;
    blocked_rows: number;
    imported_rows: number;
    failed_rows: number;
    skipped_rows: number;
    imported_with_warnings: number;
    resumed?: boolean;
    created_by: string | null;
    started_at: string | null;
    last_progress_at?: string | null;
    completed_at: string | null;
    created_at: string | null;
}

export interface HistoricalImportBatchRow {
    row: number;
    employee_no: string | null;
    employee_name: string | null;
    vessel: string | null;
    rank: string | null;
    status: string;
    status_label: string;
    assignment_no: string | null;
    crew_assignment_id: number | null;
    warnings: string[];
    errors: string[];
}

export interface HistoricalImportBatchDetail extends HistoricalImportBatchSummary {
    rows: HistoricalImportBatchRow[];
}

export interface HistoricalImportPreviewResponse {
    summary: {
        total: number;
        ready: number;
        warning: number;
        blocked: number;
        importable?: number;
    };
    rows: HistoricalImportPreviewRow[];
    phase_note: string;
    recent_imports?: HistoricalImportBatchSummary[];
}
