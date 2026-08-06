import type {
    CrewMovementCorrectionFieldValue,
    CrewMovementCorrectionListItem,
} from '@/features/organization/crew-movement-corrections/types';

export type CrewTourProgressFields = {
    tour_of_duty_days: number | null;
    tour_of_duty_source: string | null;
    tour_of_duty_source_label: string | null;
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
    visa_type_id: number | null;
    visa_type_name: string | null;
    planned_join_at: string | null;
    planned_signoff_at: string | null;
    planned_travel_at: string | null;
    actual_join_at: string | null;
    actual_disembarkation_at: string | null;
    training_provider: string | null;
    training_course: string | null;
    training_started_at: string | null;
    training_expected_completion_at: string | null;
    company_timezone: string;
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
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
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
    company_visa_type: {
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
    planned_signoff_at: string | null;
    planned_travel_at?: string | null;
    created_at: string | null;
    company_timezone?: string;
    warnings: CrewAssignmentWarning[];
    available_actions: string[];
    movement_context: CrewMovementContext;
}

export interface CrewAssignmentDetail
    extends CrewTourProgressFields, CrewReliefFields {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    employee: {
        id: number;
        name: string;
        employee_no: string | null;
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
    company_visa_type: {
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
    planned_signoff_at: string | null;
    planned_travel_at: string | null;
    actual_join_at: string | null;
    actual_disembarkation_at: string | null;
    started_at: string | null;
    closed_at: string | null;
    source: string | null;
    remarks: string | null;
    created_at: string | null;
    updated_at: string | null;
    company_timezone?: string;
    phase_timeline: PhaseTimelineItem[];
    warnings: CrewAssignmentWarning[];
    available_actions: string[];
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
    company_visa_type_id: number | null;
    planned_join_at: string;
    planned_signoff_at: string;
    planned_travel_at: string;
    remarks: string;
}

export interface CrewAssignmentFormOptions {
    employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
        rank_id: number | null;
    }>;
    ranks: Array<{
        id: number;
        name: string;
        global_tour_of_duty_days: number | null;
        company_tour_of_duty_days: number | null;
        resolved_tour_of_duty_days: number | null;
        resolved_tour_of_duty_source: string | null;
    }>;
    vessels: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    visa_types: Array<{ id: number; name: string }>;
    courses: Array<{ id: number; name: string }>;
}

export interface CrewAssignmentSummary {
    total: number;
    needs_attention: number;
    by_phase: Record<string, number>;
}

export type CrewFilterOption = {
    value: string;
    label: string;
};

export interface CrewAssignmentFilterOptions {
    vessels: Array<{ id: number; name: string }>;
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
    update: boolean;
    perform_movement: boolean;
    cancel: boolean;
    view_audit: boolean;
    request_correction: boolean;
    view_corrections: boolean;
    approve_corrections: boolean;
    override_corrections: boolean;
}

export interface CorrectablePhase {
    id: number;
    phase_code: string;
    phase_label: string;
    status: string;
    status_label: string;
    actual_start_at: string | null;
    actual_end_at: string | null;
    remarks: string | null;
    details: Record<string, unknown> | null;
    allowed_fields: string[];
    has_pending_correction: boolean;
    current_values: Record<string, CrewMovementCorrectionFieldValue>;
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
    approve_mobilisation: 'Approve Mobilisation',
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
    planned_start_at: string;
    planned_end_at: string;
    remarks: string;
    vessel_id: number | null;
    rank_id: number | null;
    client_id: number | null;
    company_visa_type_id: number | null;
    planned_signoff_at: string;
    planned_travel_at: string;
    reason: string;
    tour_of_duty_days: number | null | '';
    planned_signoff_choice:
        | 'tour_of_duty'
        | 'existing_plan'
        | 'manual_override';
    planned_signoff_override_reason: string;
}
