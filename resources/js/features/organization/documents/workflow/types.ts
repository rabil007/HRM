import type { BulkSignatureRequest } from '@/features/organization/documents/bulk/types';
import type {
    BulkDocumentCounts,
    BulkDocumentTypeOption,
    BulkGenerationRun,
    LatestEmailBatch,
    LatestSignatureRepairRun,
} from '@/features/organization/documents/bulk/types';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type DocumentWorkflowPermissions = {
    view: boolean;
    create: boolean;
    review: boolean;
    approve: boolean;
    cancel: boolean;
    view_signatures: boolean;
    review_signatures: boolean;
    view_recipient_requests: boolean;
    create_recipient_requests: boolean;
    cancel_recipient_requests: boolean;
    respond_recipient_requests: boolean;
};

export type WorkflowRequestListItem = {
    id: number;
    status: string;
    status_label: string;
    requested_at: string | null;
    requested_by: { id: number; name: string };
    document: {
        id: number | null;
        title: string | null;
        employee_document_id: number | null;
    };
    employee: {
        id: number | null;
        name: string | null;
        employee_no: string | null;
    };
    current_stage: {
        id: number;
        sequence: number;
        action: string;
        action_label: string;
        status: string;
    } | null;
    assigned_to: string[];
};

export type WorkflowTaskItem = {
    id: number;
    assignee_user_id: number | null;
    assignee_name: string;
    status: string;
    status_label: string;
    decided_by: number | null;
    decision_actor_name: string | null;
    decided_at: string | null;
    decision_notes: string | null;
};

export type WorkflowStageItem = {
    id: number;
    sequence: number;
    action: string;
    action_label: string;
    completion_rule: string;
    completion_rule_label: string;
    status: string;
    status_label: string;
    started_at: string | null;
    completed_at: string | null;
    tasks: WorkflowTaskItem[];
};

export type WorkflowRequestDetail = {
    id: number;
    status: string;
    status_label: string;
    requested_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    requested_by: { id: number; name: string };
    cancelled_by: { id: number; name: string | null } | null;
    document: {
        id: number | null;
        title: string | null;
        file_url: string | null;
        employee_id: number | null;
    };
    employee: {
        id: number | null;
        name: string | null;
        employee_no: string | null;
    };
    provenance: {
        template_name: string;
        template_version: number;
        document_instance_id: number;
        document_instance_version_id: number;
        bound_version: number | null;
    } | null;
    stages: WorkflowStageItem[];
    viewer_task: {
        id: number;
        action: string;
        action_label: string;
    } | null;
};

export type WorkflowAssigneeOption = {
    id: number;
    name: string;
    email: string | null;
    can_review: boolean;
    can_approve: boolean;
};

export type WorkflowStageInput = {
    action: 'review' | 'approve';
    completion_rule: 'all' | 'any';
    assignee_user_ids: number[];
};

export type WorkflowPresetTargetInput = {
    target_type:
        | 'specific_user'
        | 'department_manager'
        | 'parent_manager'
        | 'company_role';
    target_user_id?: number | null;
    target_role_id?: number | null;
};

export type WorkflowPresetStageInput = {
    action: 'review' | 'approve';
    completion_rule: 'all' | 'any';
    targets: WorkflowPresetTargetInput[];
};

export type WorkflowPresetTargetSummary = {
    target_type: string;
    target_type_label: string;
    target_user_id: number | null;
    target_role_id: number | null;
    label: string;
};

export type WorkflowPresetStageSummary = {
    sequence: number;
    action: string;
    action_label: string;
    completion_rule: string;
    completion_rule_label: string;
    targets: WorkflowPresetTargetSummary[];
};

export type WorkflowPresetSummary = {
    id: number;
    name: string;
    description: string | null;
    status: string;
    status_label: string;
    stage_count: number;
    routing_summary: string;
    updated_at: string | null;
    stages?: WorkflowPresetStageSummary[];
};

export type WorkflowPresetPermissions = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
};

export type WorkflowPresetFormOptions = {
    users: WorkflowAssigneeOption[];
    roles: { id: number; name: string }[];
    target_types: {
        value: string;
        label: string;
        requires_user: boolean;
        requires_role: boolean;
    }[];
};

export type DocumentWorkflowPresetsIndexProps = {
    presets: WorkflowPresetSummary[];
    can: WorkflowPresetPermissions;
    form_options: WorkflowPresetFormOptions;
};

export type DocumentRequestsSignaturePayload = {
    document_type_key: string;
    document_type_options: BulkDocumentTypeOption[];
    signature_requests: BulkSignatureRequest[];
    signature_filter: string;
    email_filter: string;
    counts: BulkDocumentCounts;
    departments: { id: number; name: string }[];
    positions: { id: number; title: string }[];
    company_visa_types: { id: number; name: string }[];
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    company_name: string;
    latest_run: BulkGenerationRun | null;
    latest_email_batch: LatestEmailBatch | null;
    latest_signature_repair_run: LatestSignatureRepairRun | null;
    can: {
        generate: boolean;
        download: boolean;
        delete: boolean;
        email: boolean;
        review_signatures: boolean;
    };
};

export type DocumentRequestsIndexProps = {
    tab: 'review' | 'signatures' | 'recipient';
    can: DocumentWorkflowPermissions;
    preset_can: WorkflowPresetPermissions;
    filters: Record<string, string | boolean>;
    search: string;
    workflow_requests: WorkflowRequestListItem[];
    recipient_requests: RecipientRequestListItem[];
    pagination: PaginationMeta;
    signature_payload: DocumentRequestsSignaturePayload | null;
};

export type RecipientRequestPermissions = {
    view: boolean;
    create: boolean;
    cancel: boolean;
    respond: boolean;
};

export type RecipientRequestStatus =
    | 'awaiting_action'
    | 'completed'
    | 'expired'
    | 'cancelled'
    | 'superseded';

export type RecipientRequestListItem = {
    id: number;
    action: string;
    action_label: string;
    status: RecipientRequestStatus;
    status_label: string;
    recipient_type: string;
    recipient_type_label: string;
    recipient_role: string;
    recipient_role_label: string;
    recipient_name: string;
    requested_at: string | null;
    expires_at: string | null;
    completed_at: string | null;
    requested_by: { id: number | null; name: string | null };
    document: { id: number | null; title: string | null };
    employee: {
        id: number | null;
        name: string | null;
        employee_no: string | null;
    };
    company_signatory: {
        id: number;
        name: string;
    } | null;
    source_version: {
        id: number;
        version: number | null;
    };
    result_version: {
        id: number;
        version: number | null;
    } | null;
    respond_url: string | null;
};

export type SignatoryOption = {
    id: number;
    name: string;
    email: string | null;
};
