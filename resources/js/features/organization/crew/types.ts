export interface CrewAssignmentListItem {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    employee: {
        id: number;
        name: string;
        employee_number: string | null;
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
    } | null;
    days_in_phase: number | null;
    planned_join_at: string | null;
    planned_signoff_at: string | null;
    created_at: string | null;
    warnings: CrewAssignmentWarning[];
}

export interface CrewAssignmentDetail {
    id: number;
    assignment_no: string;
    status: string;
    status_label: string;
    employee: {
        id: number;
        name: string;
        employee_number: string | null;
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
    } | null;
    days_in_phase: number | null;
    planned_join_at: string | null;
    planned_signoff_at: string | null;
    planned_travel_at: string | null;
    started_at: string | null;
    closed_at: string | null;
    source: string | null;
    remarks: string | null;
    created_at: string | null;
    updated_at: string | null;
    phase_timeline: PhaseTimelineItem[];
    warnings: CrewAssignmentWarning[];
    available_actions: string[];
    planning_assignment_id: number | null;
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
        employee_number: string | null;
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
