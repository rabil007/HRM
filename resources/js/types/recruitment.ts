import type {
    CandidateFormOptions,
    CandidatePagePermissions,
    CandidateRequirementSummary,
} from '@/features/organization/recruitment/candidates/types';

export type RequirementStatus =
    | 'draft'
    | 'pending_approval'
    | 'returned'
    | 'open'
    | 'on_hold'
    | 'completed'
    | 'cancelled';
export type RequirementLineStatus = 'open' | 'on_hold' | 'filled' | 'cancelled';
export type RequirementPriority = 'normal' | 'urgent';
export type RequirementDeadlineHealth = 'on_track' | 'due_soon' | 'overdue';

export type RecruitmentClockState =
    | 'not_started'
    | 'running'
    | 'paused'
    | 'completed'
    | 'cancelled';

export type RecruitmentStartSource = 'approved_at' | 'opened_at';

export type DeadlineExtensionStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type DeadlineExtensionInitiator = 'requester' | 'recruiter';

export type DeadlineExtensionMode = 'direct' | 'request';

export type HeadcountRevisionStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type HeadcountRevisionInitiator = 'requester' | 'recruiter';

export type HeadcountRevisionMode = 'direct' | 'requester' | 'recruiter';

export type RequirementHeadcountRevisionLine = {
    id: number;
    recruitment_requirement_line_id: number | null;
    position_id: number | null;
    position_title: string;
    old_headcount: number;
    requested_headcount: number;
};

export type RequirementHeadcountRevision = {
    id: number;
    initiator: HeadcountRevisionInitiator;
    initiator_label: string;
    status: HeadcountRevisionStatus;
    status_label: string;
    note_label: string;
    reason: string | null;
    decision_note: string | null;
    requested_by: number | null;
    requested_by_name: string | null;
    decided_by: number | null;
    decided_by_name: string | null;
    requested_at_formatted: string;
    decided_at_formatted: string | null;
    lines: RequirementHeadcountRevisionLine[];
};

export type RequirementDeadlineExtension = {
    id: number;
    initiator: DeadlineExtensionInitiator;
    initiator_label: string;
    status: DeadlineExtensionStatus;
    status_label: string;
    old_deadline: string | null;
    old_deadline_formatted: string;
    requested_deadline: string | null;
    requested_deadline_formatted: string;
    reason: string | null;
    decision_note: string | null;
    requested_by: number | null;
    requested_by_name: string | null;
    decided_by: number | null;
    decided_by_name: string | null;
    requested_at_formatted: string;
    decided_at_formatted: string | null;
};

export type RequirementNotificationRecipient = {
    id: number;
    name: string;
    email: string;
};

export type PositionSummaryItem = {
    id: number;
    position_id: number;
    position_title: string;
    required_headcount: number;
    status: RequirementLineStatus;
    salary_min?: string | number | null;
    salary_max?: string | number | null;
    salary_currency_code?: string | null;
    salary_range_formatted?: string | null;
};

export type RequirementSubmissionReadinessItem = {
    key: string;
    label: string;
    ready: boolean;
    message: string | null;
};

export type RequirementSubmissionReadiness = {
    ready: boolean;
    remaining_count: number;
    items: RequirementSubmissionReadinessItem[];
};

export type RequirementIndexRow = {
    id: number;
    requirement_number: string;
    client_id: number | null;
    client_name: string;
    project_id: number | null;
    project_title: string | null;
    client_reference_number: string | null;
    has_legacy_client_reference?: boolean;
    location: string | null;
    priority: RequirementPriority;
    priority_label: string;
    priority_badge: string;
    status: RequirementStatus;
    status_label: string;
    status_badge: string;
    assigned_to: number | null;
    assigned_recruiter_name: string | null;
    request_received_date: string | null;
    request_received_date_formatted: string;
    required_by_date: string | null;
    required_by_date_formatted: string;
    deadline_health: RequirementDeadlineHealth | null;
    deadline_health_label?: string;
    deadline_health_badge?: string;
    days_remaining_or_overdue: number | null;
    days_label: string;
    total_headcount: number;
    positions_summary: PositionSummaryItem[];
    positions_count: number;
    repeated_from_id: number | null;
    repeated_from_number: string | null;
    next_action:
        | 'submit'
        | 'approve'
        | 'resubmit'
        | 'open'
        | 'fill'
        | 'extend'
        | 'review_deadline_extension'
        | 'review_headcount_revision'
        | 'resume'
        | 'repeat'
        | 'view';
    can_edit: boolean;
    can_submit: boolean;
    can_approve: boolean;
    can_return: boolean;
    can_resubmit: boolean;
    can_open?: boolean;
    can_hold: boolean;
    can_resume: boolean;
    can_extend: boolean;
    can_extend_deadline?: boolean;
    deadline_extension_mode?: DeadlineExtensionMode | null;
    can_decide_deadline_extension?: boolean;
    pending_deadline_extension?: RequirementDeadlineExtension | null;
    can_change_headcount: boolean;
    headcount_revision_mode?: HeadcountRevisionMode | null;
    can_decide_headcount_revision?: boolean;
    pending_headcount_revision?: RequirementHeadcountRevision | null;
    can_fill: boolean;
    can_cancel: boolean;
    can_transfer_ownership?: boolean;
    can_reopen: boolean;
    can_repeat: boolean;
    submission_readiness?: RequirementSubmissionReadiness | null;
};

export type RequirementLine = {
    id: number;
    recruitment_requirement_id: number;
    position_id: number;
    position_title: string;
    department_name: string | null;
    grade: string | null;
    required_headcount: number;
    joined_count?: number;
    remaining_headcount?: number;
    is_overfilled?: boolean;
    target_reached?: boolean;
    can_mark_filled?: boolean;
    line_notes: string | null;
    status: RequirementLineStatus;
    status_label: string;
    status_badge: string;
    salary_min: string | number | null;
    salary_max: string | number | null;
    salary_currency_code: string | null;
    salary_range_formatted: string | null;
};

export type RequirementAttachment = {
    id: number;
    original_file_name: string;
    mime_type: string;
    file_size_bytes: number;
    file_size_formatted: string;
    uploader_name: string | null;
    created_at_formatted: string;
};

export type RequirementDetail = RequirementIndexRow & {
    notes: string | null;
    cancellation_reason: string | null;
    return_reason: string | null;
    opened_at_formatted: string | null;
    submitted_at_formatted: string | null;
    returned_at_formatted: string | null;
    completed_at_formatted: string | null;
    cancelled_at_formatted: string | null;
    created_at_formatted: string | null;
    created_by?: number | null;
    creator_name: string | null;
    updater_name: string | null;
    submitter_name: string | null;
    returner_name: string | null;
    notification_recipients: RequirementNotificationRecipient[];
    recruitment_started_at: string | null;
    recruitment_started_at_formatted: string | null;
    recruitment_start_source: RecruitmentStartSource | null;
    active_recruitment_seconds: number | null;
    active_recruitment_days: number | null;
    on_hold_seconds: number;
    recruitment_duration_label: string | null;
    recruitment_clock_state: RecruitmentClockState;
    duration_is_estimated?: boolean;
    duration_estimate_note?: string | null;
    closed_seconds?: number;
    approved_at: string | null;
    approved_at_formatted: string | null;
    approved_by_name: string | null;
    has_legacy_client_reference: boolean;
    lines: RequirementLine[];
    attachments: RequirementAttachment[];
    progress: {
        filled: number;
        target: number;
        remaining?: number;
        percentage: number;
        is_target_reached: boolean;
        is_overfilled?: boolean;
        suggest_mark_filled?: boolean;
    };
    deadline_extensions?: RequirementDeadlineExtension[];
    headcount_revisions?: RequirementHeadcountRevision[];
};

export type RequirementPagePermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    submit: boolean;
    approve: boolean;
    close: boolean;
    cancel: boolean;
    reopen: boolean;
    download_attachments: boolean;
    repeat: boolean;
    view_audit: boolean;
    request_deadline_extension?: boolean;
    request_headcount_revision?: boolean;
    transfer_ownership?: boolean;
};

export type ClientOption = {
    id: number;
    name: string;
    is_active: boolean;
};

export type ProjectOption = {
    id: number;
    title: string;
    client_ids: number[];
    is_active?: boolean;
};

export type PositionOption = {
    id: number;
    title: string;
    grade: string | null;
    status: string;
    min_salary?: string | number | null;
    max_salary?: string | number | null;
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type RequirementSummaryCardsData = {
    open_headcount: number;
    overdue: number;
    due_this_week: number;
    ready_to_close: number;
};

export type RequirementTab = 'active' | 'on_hold' | 'history';

export type RequirementFilters = {
    tab: RequirementTab;
    search: string;
    client_id?: string | number | null;
    project_id?: string | number | null;
    position_id?: string | number | null;
    assigned_to?: string | number | null;
    priority?: string | null;
    deadline_health?: string | null;
    needs_action?: string | null;
    per_page?: number | null;
};

export type RequirementIndexProps = {
    requirements: {
        data: RequirementIndexRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: RequirementSummaryCardsData;
    tab_counts: {
        active: number;
        on_hold: number;
        history: number;
    };
    filters: RequirementFilters;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
        notification_users?: UserOption[];
        currency_code?: string;
    };
    can: RequirementPagePermissions;
};

export type RequirementWorkflowTimelineEvent = {
    id: string;
    key: string;
    label: string;
    occurred_at: string;
    occurred_at_formatted: string;
    actor_name: string | null;
    reason: string | null;
    previous_recruiter_name?: string | null;
    new_recruiter_name?: string | null;
    previous_requester_name?: string | null;
    new_requester_name?: string | null;
    is_current: boolean;
    state: 'completed' | 'current';
};

export type RequirementWorkflowTimeline = {
    current_stage: string;
    current_stage_label: string;
    next_expected_action: string | null;
    next_expected_action_label: string | null;
    events: RequirementWorkflowTimelineEvent[];
};

export type RequirementShowProps = {
    requirement: RequirementDetail;
    workflow_timeline: RequirementWorkflowTimeline;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
        requesters?: UserOption[];
        notification_users?: UserOption[];
        currency_code?: string;
    };
    can: RequirementPagePermissions;
    recent_activity?: Array<{
        id: number;
        description: string;
        created_at: string;
        causer_name?: string | null;
    }>;
    candidate_summary?: CandidateRequirementSummary | null;
    candidate_options?: CandidateFormOptions | null;
    candidate_can?: CandidatePagePermissions;
};
