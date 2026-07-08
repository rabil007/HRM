import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export type BulkDocumentTypeOption = {
    value: string;
    label: string;
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
        file_path: string;
        created_at: string | null;
    } | null;
};

export type BulkDocumentCounts = {
    targeted: number;
    generated: number;
    not_generated: number;
};

export type BulkGenerationRun = {
    id: number;
    status: string;
    document_type_key: string;
    total_targeted: number;
    generated_count: number;
    replaced_count: number;
    skipped_count: number;
    failed_count: number;
    started_at: string | null;
    finished_at: string | null;
    triggered_by: string | null;
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

export type BulkDocumentsPageProps = {
    document_type_key: string;
    document_type_options: BulkDocumentTypeOption[];
    view: 'roster' | 'history';
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
    generation_filter: 'all' | 'missing';
    departments: { id: number; name: string }[];
    positions: { id: number; title: string }[];
    company_visa_types: { id: number; name: string }[];
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    company_name: string;
    email_template: WiredEmailTemplate | null;
    latest_run: BulkGenerationRun | null;
    can: {
        generate: boolean;
        download: boolean;
        delete: boolean;
        email: boolean;
    };
};
