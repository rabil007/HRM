export type CandidateStage =
    | 'applied'
    | 'screening'
    | 'interview'
    | 'offer_jol'
    | 'joining'
    | 'joined'
    | 'rejected';

export type CandidateInterviewOutcome = 'selected' | 'not_selected' | null;

export type CandidateOfferStatus = 'draft' | 'sent' | 'accepted' | 'rejected';

export type CandidateJoiningReadinessStatus = 'pending' | 'ready' | null;

export type CandidateJoiningScheduleUrgency =
    | 'overdue'
    | 'today'
    | 'upcoming'
    | null;

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
    offer_status: CandidateOfferStatus | null;
    offer_status_label: string | null;
    offer_status_badge: string | null;
    requirement_id: number | null;
    requirement_number: string;
    requirement_line_id: number | null;
    position_title: string;
    source: string | null;
    source_label: string | null;
    nationality: string | null;
    has_cv: boolean;
    interview_scheduled_at: string | null;
    expected_joining_date: string | null;
    actual_joining_date: string | null;
    joining_readiness_status: CandidateJoiningReadinessStatus;
    joining_readiness_notes: string | null;
    joining_blocker_notes: string | null;
    joining_schedule_urgency: CandidateJoiningScheduleUrgency;
    joining_schedule_label: string | null;
    lock_version: number;
    created_at: string | null;
    parents_valid: boolean;
    can_update: boolean;
    can_move: boolean;
    can_move_forward: boolean;
    can_reject: boolean;
    can_select: boolean;
    can_undo_selected: boolean;
    can_prepare_offer: boolean;
    can_reopen: boolean;
    can_download_cv: boolean;
    can_confirm_joined: boolean;
    can_correct_joined: boolean;
    can_update_readiness: boolean;
    joined_by_name: string | null;
    joined_at: string | null;
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

export type CandidateOfferDetail = {
    id: number;
    revision_number: number;
    is_current: boolean;
    supersedes_offer_id: number | null;
    status: CandidateOfferStatus;
    status_label: string;
    status_badge: string;
    salary_amount: string;
    salary_currency_code: string;
    proposed_joining_date: string | null;
    offer_date: string | null;
    expiry_date: string | null;
    notes: string | null;
    sent_at: string | null;
    sent_by_name: string | null;
    accepted_at: string | null;
    accepted_by_name: string | null;
    rejected_at: string | null;
    rejected_by_name: string | null;
    rejection_reason: string | null;
    revision_reason: string | null;
    lock_version: number;
    has_offer_document: boolean;
    offer_document_original_file_name: string | null;
    has_acceptance_document: boolean;
    acceptance_document_original_file_name: string | null;
    can_update: boolean;
    can_send: boolean;
    can_accept: boolean;
    can_reject: boolean;
    can_revise: boolean;
    can_download_offer_document: boolean;
    can_download_acceptance_document: boolean;
};

export type CandidateOfferHistoryItem = {
    id: number;
    revision_number: number;
    is_current: boolean;
    status: CandidateOfferStatus;
    status_label: string;
    salary_amount: string;
    salary_currency_code: string;
    revision_reason: string | null;
    created_at: string | null;
};

export type CandidateJoiningDetail = {
    expected_joining_date: string | null;
    actual_joining_date: string | null;
    readiness_status: CandidateJoiningReadinessStatus;
    readiness_notes: string | null;
    blocker_notes: string | null;
    joined_at: string | null;
    joined_by_name: string | null;
    joined_by_id: number | null;
    can_update_readiness: boolean;
    can_confirm_joined: boolean;
    can_correct_joined: boolean;
    schedule_urgency: CandidateJoiningScheduleUrgency;
    schedule_label: string | null;
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
        salary_min: string | null;
        salary_max: string | null;
        salary_currency_code: string | null;
    } | null;
    current_offer: CandidateOfferDetail | null;
    offer_history: CandidateOfferHistoryItem[];
    joining: CandidateJoiningDetail | null;
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
            salary_min?: string | null;
            salary_max?: string | null;
            salary_currency_code?: string | null;
        }>;
    }>;
    nationalities: Array<{ id: number; name: string }>;
    currencies: Array<{ code: string; name: string; symbol: string }>;
    default_currency_code: string | null;
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
    offer_prepare: boolean;
    offer_update: boolean;
    offer_send: boolean;
    offer_decide: boolean;
    offer_revise: boolean;
    offer_download: boolean;
    joining_confirm: boolean;
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

export type CandidateBrowseOptions = {
    requirements: Array<{
        id: number;
        requirement_number: string;
        status?: string;
        status_label?: string;
        client_name: string | null;
        assigned_to: number | null;
        assigned_to_name: string | null;
        lines: Array<{
            id: number;
            position_id: number;
            position_title: string;
            status?: string;
            status_label?: string;
        }>;
    }>;
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
    browse_options: CandidateBrowseOptions;
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
    'offer_jol',
    'joining',
    'joined',
    'rejected',
];

export const CANDIDATE_STAGE_LABELS: Record<CandidateStage, string> = {
    applied: 'Applied',
    screening: 'Screening',
    interview: 'Interview',
    offer_jol: 'Offer/JOL',
    joining: 'Joining',
    joined: 'Joined',
    rejected: 'Rejected',
};

export const CANDIDATE_OFFER_STATUS_LABELS: Record<
    CandidateOfferStatus,
    string
> = {
    draft: 'Draft',
    sent: 'Sent',
    accepted: 'Accepted',
    rejected: 'Rejected',
};
