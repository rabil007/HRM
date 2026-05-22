export type EmployeeFolder = {
    employee_id: number;
    employee_name: string;
    employee_no: string;
    document_count: number;
};

export type EmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
    email?: string | null;
};

export type DocumentExpiryStatus =
    | 'valid'
    | 'expiring_30'
    | 'expiring_15'
    | 'expiring_7'
    | 'expired';

export type DocumentBrowseItem = {
    id: number;
    document_name: string;
    document_type: string;
    file_url: string;
    uploaded_at: string | null;
    uploaded_by: string | null;
    mime_type: string | null;
    can_preview: boolean;
    status: string | null;
    expiry_date: string | null;
    issue_date: string | null;
    document_number: string | null;
    size_bytes: number | null;
    expiry_status: DocumentExpiryStatus | null;
    remaining_days: number | null;
    expiry_label: string;
};

export type DocumentProfileItem = DocumentBrowseItem & {
    title: string | null;
    type: string | null;
    document_type_id: number | null;
    document_type: string | null;
    document_type_label: string | null;
    file_path: string;
    original_filename: string | null;
    document_number: string | null;
    notes: string | null;
    current_version: number | null;
    versions_count?: number;
    uploaded_by: string | null;
    created_at: string | null;
    versions: {
        id: number;
        version: number;
        file_url: string;
        original_filename: string | null;
        mime_type: string | null;
        size_bytes: number | null;
        replaced_by: string | null;
        created_at: string;
    }[];
};

export type ComplianceDocumentItem = DocumentBrowseItem & {
    employee_id: number;
    employee_name: string;
    employee_no: string;
};

export type DocumentExpirySummary = {
    total_documents: number;
    expired: number;
    expiring_30: number;
    expiring_15: number;
    expiring_7: number;
};

export type PaginatedComplianceDocuments = {
    data: ComplianceDocumentItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type DocumentTypeOption = {
    id: number;
    title: string;
};

export type PreviewDocument = {
    title: string | null;
    document_type_label?: string | null;
    file_url: string;
    mime_type?: string | null;
    can_preview?: boolean;
};
