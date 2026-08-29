export type SigningPresetStep = {
    sequence: number;
    recipient_role: 'subject' | 'manager' | 'company_signatory';
    recipient_role_label: string;
    step_label: string | null;
    display_label: string;
    target_type: string;
    target_user_id: number | null;
    target_user: { id: number; name: string; email: string | null } | null;
};

export type SigningPresetSummary = {
    id: number;
    name: string;
    description: string | null;
    status: 'active' | 'inactive';
    status_label: string;
    is_active: boolean;
    routing_summary: string;
    steps: SigningPresetStep[];
    created_at: string | null;
    updated_at: string | null;
};

export type SigningPresetPermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
};

export type SigningPresetFormOptions = {
    users: Array<{ id: number; name: string; email: string | null }>;
};

export type DocumentSigningPresetsIndexProps = {
    presets: SigningPresetSummary[];
    can: SigningPresetPermissions;
    form_options: SigningPresetFormOptions;
};

export type SigningFlowStepSummary = {
    sequence: number;
    total_steps?: number;
    recipient_role: string | null;
    recipient_role_label?: string | null;
    step_label?: string | null;
    signature_slot_key?: string | null;
    recipient_name: string | null;
    status: string;
    is_current: boolean;
    request_id: number | null;
    source_version: number | null | undefined;
    result_version: number | null | undefined;
    email_delivery?: {
        status: string;
        status_label: string;
        last_sent_at: string | null;
        can_resend: boolean;
    } | null;
    respond_url: string | null;
};

export type SigningFlowSummary = {
    id: number;
    preset_name: string;
    status: string;
    status_label: string;
    current_step_sequence: number | null;
    started_by: { id: number; name: string } | null;
    started_at: string | null;
    blocked_at: string | null;
    blocked_reason: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    steps: SigningFlowStepSummary[];
    can_retry: boolean;
    can_cancel: boolean;
};

export type DocumentShowSigningFlowProps = {
    can_start: boolean;
    blocked_reason: string | null;
    presets: SigningPresetSummary[];
    active_flow: SigningFlowSummary | null;
};
