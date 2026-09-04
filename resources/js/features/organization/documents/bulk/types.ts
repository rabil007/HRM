import type { OperationalProcess } from '@/features/organization/documents/journey/types';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type BulkDocumentTypeOption = {
    value: string;
    label: string;
    category?: string;
    is_custom?: boolean;
    template_id?: number;
    template_format?: string;
};

export type BulkDocumentFilters = {
    department_id: string;
    position_id: string;
    company_visa_type_id: string;
};

export const EMPTY_BULK_DOCUMENT_FILTERS: BulkDocumentFilters = {
    department_id: '',
    position_id: '',
    company_visa_type_id: '',
};

export type BulkRosterEmployee = {
    id: number;
    name: string;
    employee_no: string | null;
    image: string | null;
    department: string | null;
    position: string | null;
    email: string | null;
    status: string;
    document: {
        id: number;
        file_path?: string;
        created_at: string | null;
    } | null;
    email_sent_at: string | null;
    signature_status: string | null;
    generation_run_status?:
        | 'pending'
        | 'processing'
        | 'completed'
        | 'skipped'
        | 'failed'
        | null;
    generation_error?: {
        code: string;
        message: string;
    } | null;
    process?: OperationalProcess;
};

export type BulkGenerationFailureSummary = {
    count: number;
    headline: string;
    show_edit_template: boolean;
    additional_failure_count: number;
    items: {
        employee_id: number;
        employee_name: string;
        error_code: string;
        message: string;
    }[];
};

export type ProcessLifecycleFilter =
    | 'all'
    | 'not_started'
    | 'in_progress'
    | 'needs_attention'
    | 'completed';

export type BulkDocumentCounts = {
    targeted: number;
    generated: number;
    not_generated: number;
    pending_review: number;
    awaiting_signature: number;
    approved: number;
    all?: number;
    not_started?: number;
    in_progress?: number;
    needs_attention?: number;
    completed?: number;
};

export type BulkGenerationRun = {
    id: number;
    source?: 'company_template' | 'built_in';
    status: string;
    document_type_key?: string;
    total_targeted: number;
    generated_count: number;
    replaced_count: number;
    skipped_count: number;
    failed_count: number;
    pending_count?: number;
    processing_count?: number;
    processed_count: number;
    progress_percent: number;
    template_id?: number;
    template_version_id?: number;
    template_name?: string | null;
    template_version?: number | null;
    started_at: string | null;
    finished_at: string | null;
    triggered_by: {
        id: number;
        name: string;
    } | null;
    failure_summary?: BulkGenerationFailureSummary | null;
};

export type WiredEmailTemplate = {
    id: number;
    slug: string;
    label: string;
    subject: string;
    body_html: string;
    to_preset: string | null;
    cc_preset: string | null;
};

export type BulkActivityItem =
    | {
          kind: 'generation';
          id: number;
          document_type_key: string;
          document_type_label: string;
          status: string;
          generated_count: number;
          replaced_count: number;
          skipped_count: number;
          failed_count: number;
          created_at: string | null;
          triggered_by: string | null;
      }
    | {
          kind: 'email';
          id: number;
          document_type_key: string;
          document_type_label: string;
          template_label: string | null;
          sent_count: number;
          failed_count: number;
          skipped_no_email_count: number;
          created_at: string | null;
          triggered_by: string | null;
      };

export type BulkEmailBatchSend = {
    id: number;
    employee: {
        id: number;
        name: string | null;
        employee_no: string | null;
    };
    recipient_email: string | null;
    status: 'sent' | 'failed' | 'skipped';
    error: string | null;
    sent_at: string | null;
};

export type BulkEmailBatchDetail = {
    id: number;
    document_type_key: string;
    document_type_label: string;
    subject: string;
    template_label: string | null;
    status: string;
    total_selected: number;
    sent_count: number;
    failed_count: number;
    skipped_no_email_count: number;
    created_at: string | null;
    triggered_by: string | null;
};

export type BulkEmailBatchSendsResponse = {
    batch: BulkEmailBatchDetail;
    sends: BulkEmailBatchSend[];
};

export type BulkGenerationFilter = 'all' | 'missing' | 'generated';

export type BulkEmailFilter = 'all' | 'emailed' | 'not_emailed';

export type LatestEmailBatch = {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed';
    total_selected: number;
    sent_count: number;
    failed_count: number;
    skipped_no_email_count: number;
    started_at: string | null;
    finished_at: string | null;
    triggered_by: string | null;
};

export type BulkDocumentsPageProps = {
    document_type_key: string;
    document_type_options: BulkDocumentTypeOption[];
    is_custom_template?: boolean;
    custom_template?: {
        id: number;
        name: string;
        version: number;
        template_format: string;
    } | null;
    view: 'roster' | 'history';
    can_view_templates?: boolean;
    module_view_locked?: boolean;
    filters: {
        department_id: string;
        position_id: string;
        company_visa_type_id: string;
        search: string;
    };
    search: string;
    counts: BulkDocumentCounts;
    employees: BulkRosterEmployee[];
    activity: BulkActivityItem[];
    pagination: PaginationMeta;
    process_filter?: ProcessLifecycleFilter;
    generation_filter: BulkGenerationFilter;
    email_filter: BulkEmailFilter;
    departments: { id: number; name: string }[];
    positions: { id: number; title: string }[];
    company_visa_types: { id: number; name: string }[];
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    company_name: string;
    email_template: WiredEmailTemplate | null;
    reminder_email_template: WiredEmailTemplate | null;
    latest_run: BulkGenerationRun | null;
    latest_email_batch: LatestEmailBatch | null;
    can: {
        generate: boolean;
        download: boolean;
        delete: boolean;
        email: boolean;
    };
};
