export type RequirementStatus =
    | 'draft'
    | 'open'
    | 'on_hold'
    | 'completed'
    | 'cancelled';
export type RequirementLineStatus = 'open' | 'on_hold' | 'filled' | 'cancelled';
export type RequirementPriority = 'normal' | 'urgent';
export type RequirementDeadlineHealth = 'on_track' | 'due_soon' | 'overdue';

export type PositionSummaryItem = {
    id: number;
    position_id: number;
    position_title: string;
    required_headcount: number;
    status: RequirementLineStatus;
};

export type RequirementIndexRow = {
    id: number;
    requirement_number: string;
    client_id: number;
    client_name: string;
    project_id: number | null;
    project_title: string | null;
    client_reference_number: string | null;
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
    next_action: 'open' | 'fill' | 'extend' | 'resume' | 'repeat';
    can_edit: boolean;
    can_open: boolean;
    can_hold: boolean;
    can_resume: boolean;
    can_extend: boolean;
    can_change_headcount: boolean;
    can_fill: boolean;
    can_cancel: boolean;
    can_reopen: boolean;
    can_repeat: boolean;
};

export type RequirementLine = {
    id: number;
    recruitment_requirement_id: number;
    position_id: number;
    position_title: string;
    department_name: string | null;
    grade: string | null;
    required_headcount: number;
    line_notes: string | null;
    status: RequirementLineStatus;
    status_label: string;
    status_badge: string;
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
    opened_at_formatted: string | null;
    completed_at_formatted: string | null;
    cancelled_at_formatted: string | null;
    created_at_formatted: string | null;
    creator_name: string | null;
    updater_name: string | null;
    lines: RequirementLine[];
    attachments: RequirementAttachment[];
    progress: {
        filled: number;
        target: number;
        percentage: number;
        is_target_reached: boolean;
    };
};

export type RequirementPagePermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    close: boolean;
    cancel: boolean;
    reopen: boolean;
    download_attachments: boolean;
    repeat: boolean;
    view_audit: boolean;
};

export type ClientOption = {
    id: number;
    name: string;
    is_active: boolean;
};

export type ProjectOption = {
    id: number;
    client_id: number;
    title: string;
    is_active: boolean;
};

export type PositionOption = {
    id: number;
    title: string;
    grade: string | null;
    status: string;
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
    };
    can: RequirementPagePermissions;
};

export type RequirementShowProps = {
    requirement: RequirementDetail;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
    };
    can: RequirementPagePermissions;
    recent_activity?: Array<{
        id: number;
        description: string;
        created_at: string;
        causer_name?: string | null;
    }>;
};
