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
};

export interface CrewAssignmentListItem {
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

export interface CrewAssignmentDetail {
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
        status_label: string;
        started_at?: string | null;
    } | null;
    days_in_phase: number | null;
    days_onboard?: number | null;
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
    ranks: Array<{ id: number; name: string }>;
    vessels: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    visa_types: Array<{ id: number; name: string }>;
}

export interface CrewAssignmentSummary {
    total: number;
    needs_attention: number;
    by_phase: Record<string, number>;
}

export interface CrewAssignmentFilterOptions {
    vessels: Array<{ id: number; name: string }>;
    ranks: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    employees: Array<{
        id: number;
        name: string;
        employee_no: string | null;
    }>;
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
}

export interface CrewAssignmentPagePermissions {
    view: boolean;
    create: boolean;
    update: boolean;
    perform_movement: boolean;
    cancel: boolean;
    view_audit: boolean;
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
}
