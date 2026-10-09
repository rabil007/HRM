export type CandidateStage = 'applied' | 'screening' | 'interview' | 'rejected';

export type CandidateInterviewOutcome = 'selected' | 'not_selected' | null;

export type CandidateIndexRow = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    stage: CandidateStage;
    stage_label: string;
    stage_badge: string;
    interview_outcome: CandidateInterviewOutcome;
    interview_outcome_label: string | null;
    interview_outcome_badge: string | null;
    requirement_id: number | null;
    requirement_number: string;
    requirement_line_id: number | null;
    position_title: string;
    source: string | null;
    source_label: string | null;
    nationality: string | null;
    has_cv: boolean;
    interview_scheduled_at: string | null;
    lock_version: number;
    created_at: string | null;
    parents_valid: boolean;
    can_update: boolean;
    can_move: boolean;
    can_move_forward: boolean;
    can_reject: boolean;
    can_select: boolean;
    can_undo_selected: boolean;
    can_reopen: boolean;
    can_download_cv: boolean;
};

export type CandidateMovementEvent = {
    id: number;
    action: string;
    action_label: string;
    from_stage: string | null;
    from_stage_label: string | null;
    to_stage: string;
    to_stage_label: string;
    from_outcome: string | null;
    from_outcome_label: string | null;
    to_outcome: string | null;
    to_outcome_label: string | null;
    reason: string | null;
    context: Record<string, unknown> | null;
    performed_by: number | null;
    performed_by_name: string | null;
    created_at: string | null;
};

export type CandidateDetail = CandidateIndexRow & {
    notes: string | null;
    rejection_reason: string | null;
    pre_rejection_stage: string | null;
    cv_original_file_name: string | null;
    nationality_id: number | null;
    interview: {
        scheduled_at: string | null;
        scheduled_at_formatted: string | null;
        interviewer_user_id: number | null;
        interviewer_user_name: string | null;
        external_interviewer_name: string | null;
        mode: string | null;
        mode_label: string | null;
        location: string | null;
        feedback: string | null;
    };
    requirement: {
        id: number;
        requirement_number: string;
        status: string;
        status_label: string;
        assigned_to: number | null;
        assigned_to_name: string | null;
        client_name: string | null;
        project_title: string | null;
    } | null;
    line: {
        id: number;
        position_id: number;
        position_title: string;
        status: string;
        status_label: string;
    } | null;
    movement_history: CandidateMovementEvent[];
    timezone: string;
};

export type CandidateFormOptions = {
    requirements: Array<{
        id: number;
        requirement_number: string;
        client_name: string | null;
        assigned_to: number | null;
        assigned_to_name: string | null;
        lines: Array<{
            id: number;
            position_id: number;
            position_title: string;
        }>;
    }>;
    nationalities: Array<{ id: number; name: string }>;
    sources: Array<{ value: string; label: string }>;
    interview_modes: Array<{ value: string; label: string }>;
    interviewers: Array<{ id: number; name: string; email: string }>;
};

export type CandidatePagePermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    move: boolean;
    manage: boolean;
    download_cv: boolean;
    view_audit: boolean;
};

export type CandidateFilters = {
    view: 'table' | 'kanban';
    search: string;
    per_page: number;
    requirement_id: number | null;
    requirement_line_id: number | null;
    position_id: number | null;
    stage: string | null;
    outcome: string | null;
};

export type CandidateFormData = {
    recruitment_requirement_id: string;
    recruitment_requirement_line_id: string;
    name: string;
    email: string;
    phone: string;
    nationality_id: string;
    source: string;
    notes: string;
    cv: File | null;
    remove_cv: boolean;
    ignore_duplicate_warning: boolean;
    lock_version: number | null;
    _method?: string;
};

export type CandidateKanbanColumn = {
    total: number;
    data: CandidateIndexRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
};

export type CandidateIndexProps = {
    candidates: {
        data: CandidateIndexRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    } | null;
    kanban: Record<string, CandidateKanbanColumn> | null;
    stage_totals: Record<string, number>;
    filters: CandidateFilters;
    search: string;
    options: CandidateFormOptions;
    can: CandidatePagePermissions;
    timezone: string;
};

export type CandidateShowProps = {
    candidate: CandidateDetail;
    options: CandidateFormOptions;
    can: CandidatePagePermissions;
    recent_activity: Array<{
        id: number;
        description: string;
        created_at: string;
        causer_name?: string | null;
    }>;
    can_view_audit: boolean;
    timezone: string;
};

export type CandidateRequirementSummary = {
    total: number;
    by_stage: Record<string, number>;
    selected: number;
    recent: CandidateIndexRow[];
};

export const CANDIDATE_KANBAN_STAGES: CandidateStage[] = [
    'applied',
    'screening',
    'interview',
    'rejected',
];

export const CANDIDATE_STAGE_LABELS: Record<CandidateStage, string> = {
    applied: 'Applied',
    screening: 'Screening',
    interview: 'Interview',
    rejected: 'Rejected',
};
