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
    tab: 'review' | 'signatures';
    can: DocumentWorkflowPermissions;
    filters: Record<string, string | boolean>;
    search: string;
    workflow_requests: WorkflowRequestListItem[];
    pagination: PaginationMeta;
    signature_payload: DocumentRequestsSignaturePayload | null;
};
