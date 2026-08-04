export type CrewTimelineUserRef = {
    id: number;
    name: string;
};

export type CrewTimelineWarning = {
    code: string;
    label: string;
    is_blocking: boolean;
};

export type CrewTimelinePhaseWarning = CrewTimelineWarning & {
    remarks: string | null;
    from_date: string | null;
    to_date: string | null;
    line_id: number;
};

export type CrewTimelineWarningBreakdownItem = {
    code: string;
    label: string;
    is_blocking: boolean;
    count: number;
};

export type CrewTimelineLine = {
    id: number;
    crew_assignment_id?: number | null;
    crew_assignment_phase_id?: number | null;
    phase_code: string | null;
    phase_label: string | null;
    pay_category: string | null;
    pay_category_label: string | null;
    from_date: string | null;
    to_date: string | null;
    days: string;
    source_actual_start: string | null;
    source_actual_end: string | null;
    warning: CrewTimelineWarning | null;
    remarks: string | null;
};

export type CrewTimelinePhaseTreatment = {
    pay_category: string;
    pay_category_label: string;
    from_date: string | null;
    to_date: string | null;
    days: string;
};

export type CrewTimelinePhaseOccurrence = {
    id: number | null;
    phase_code: string | null;
    phase_code_display: string | null;
    phase_label: string | null;
    sequence: number | null;
    status: string | null;
    status_label: string | null;
    planned_start: string | null;
    planned_end: string | null;
    planned_date_origin: string | null;
    planned_date_origin_label: string | null;
    actual_start: string | null;
    actual_end: string | null;
    actual_date_origin: string | null;
    actual_date_origin_label: string | null;
    payroll_from: string | null;
    payroll_to: string | null;
    payroll_date_origin: string | null;
    payroll_date_origin_label: string | null;
    payroll_period_label: string | null;
    payroll_lines: CrewTimelineLine[];
    primary_treatment: CrewTimelinePhaseTreatment | null;
    excluded_treatment: CrewTimelinePhaseTreatment | null;
    payable_from: string | null;
    payable_to: string | null;
    payable_days: string;
    is_operational: boolean;
    warnings: CrewTimelinePhaseWarning[];
    remarks: string[];
    occurrence: number | null;
    occurrence_count: number;
    has_planned_schedule: boolean;
    has_payroll_period: boolean;
};

export type CrewTimelineAssignmentSummary = {
    id: number | null;
    assignment_number: string | null;
    source: string | null;
    source_label: string;
    status: string | null;
    status_label: string | null;
    previous_assignment_id: number | null;
    previous_assignment_number: string | null;
    previous_vessel: string | null;
    vessel: string | null;
    client: string | null;
    rank: string | null;
    phases: CrewTimelinePhaseOccurrence[];
};

export type CrewTimelineEmployeeSummary = {
    employee_id: number;
    employee_number: string | null;
    employee_name: string | null;
    rank: string | null;
    assignment_id: number | null;
    assignment_number: string | null;
    vessel: string | null;
    assignment_count: number;
    sign_on_standby_from: string | null;
    sign_on_standby_to: string | null;
    sign_on_standby_days: number;
    onsite_from: string | null;
    onsite_to: string | null;
    onsite_days: number;
    sign_off_standby_from: string | null;
    sign_off_standby_to: string | null;
    sign_off_standby_days: number;
    total_payable_days: number;
    blocking_warning_count: number;
    informational_warning_count: number;
    assignments: CrewTimelineAssignmentSummary[];
    lines: CrewTimelineLine[];
};

export type CrewTimelinePreparationStatus =
    | 'draft'
    | 'submitted'
    | 'returned'
    | 'approved'
    | 'applied'
    | 'superseded';

export type CrewTimelinePreparation = {
    id: number;
    version: number;
    status: CrewTimelinePreparationStatus;
    status_label: string;
    cutoff_date: string | null;
    source_hash: string | null;
    is_fresh: boolean;
    is_stale: boolean;
    is_latest: boolean;
    prepared_by: CrewTimelineUserRef | null;
    prepared_at: string | null;
    submitted_by: CrewTimelineUserRef | null;
    submitted_at: string | null;
    approved_by: CrewTimelineUserRef | null;
    approved_at: string | null;
    returned_by: CrewTimelineUserRef | null;
    returned_at: string | null;
    applied_by: CrewTimelineUserRef | null;
    applied_at: string | null;
    linked_timesheet_count: number;
    decision_notes: string | null;
};

export type CrewTimelinePeriod = {
    id: number;
    name: string;
    start_date: string | null;
    end_date: string | null;
    status: string | null;
    status_label: string | null;
};

export type CrewTimelineSummary = {
    total_employees: number;
    total_sign_on_standby_days: string;
    total_onsite_days: string;
    total_sign_off_standby_days: string;
    blocking_warning_count: number;
    informational_warning_count: number;
};

export type CrewTimelinePagePermissions = {
    view: boolean;
    prepare: boolean;
    submit: boolean;
    approve: boolean;
    return: boolean;
    apply: boolean;
    view_audit: boolean;
};

export type CrewTimelineShowProps = {
    period: CrewTimelinePeriod;
    preparation: CrewTimelinePreparation;
    summary: CrewTimelineSummary;
    employees: CrewTimelineEmployeeSummary[];
    permissions: CrewTimelinePagePermissions;
};

export type CrewTimelinePreparationSummary = {
    id: number;
    version: number;
    status: CrewTimelinePreparationStatus;
    status_label: string;
    is_fresh: boolean;
    is_stale: boolean;
    blocking_warning_count: number;
    informational_warning_count: number;
    prepared_at: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    returned_at: string | null;
    applied_at: string | null;
    linked_timesheet_count: number;
};
