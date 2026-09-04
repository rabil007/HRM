export type OperationalTone =
    | 'neutral'
    | 'info'
    | 'warning'
    | 'success'
    | 'danger';

export type OperationalStage =
    | 'review'
    | 'signing'
    | 'delivery'
    | 'generation'
    | null;

export type OperationalProcess = {
    status: string;
    label: string;
    tone: OperationalTone;
    stage: OperationalStage;
    waiting_for: string | null;
    last_activity: {
        event: string;
        timestamp: string | null;
        relative: string | null;
    } | null;
    action_email: {
        status: string;
        failure_category: string | null;
        failure_message: string | null;
        attempted_at: string | null;
    } | null;
    document_copy_email: {
        status: string;
        sent_at: string | null;
    };
    authorized_action_url: string | null;
    workflow_request_id: number | null;
    signing_flow_id: number | null;
    recipient_request_id: number | null;
    document_instance_id: number | null;
    employee_document_id: number | null;
};

export type JourneyEmployee = {
    id: number;
    name: string;
    employee_no: string | null;
    department: string | null;
    position: string | null;
};

export type JourneyDocument = {
    id: number | null;
    instance_id: number | null;
    title: string;
    document_type: string | null;
    version_number: number | null;
    generated_at: string | null;
    view_url: string | null;
    details_url: string | null;
};

export type JourneyEvent = {
    id: string;
    type: string;
    title: string;
    description: string | null;
    actor: string | null;
    status: string | null;
    timestamp: string | null;
    relative: string | null;
    metadata?: Record<string, unknown>;
};

export type ActionEmailBanner = {
    show: boolean;
    category: string | null;
    message: string | null;
    can_resend: boolean;
    recipient_request_id: number | null;
};

export type JourneyPermissions = {
    can_view_document: boolean;
    can_download_document: boolean;
    can_resend_action_email: boolean;
    can_retry_lifecycle: boolean;
};

export type DocumentJourneyData = {
    employee: JourneyEmployee;
    document: JourneyDocument;
    process: OperationalProcess;
    events: JourneyEvent[];
    action_email_banner: ActionEmailBanner | null;
    permissions: JourneyPermissions;
};
