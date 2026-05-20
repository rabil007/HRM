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
    mime_type: string | null;
    can_preview: boolean;
    status: string | null;
    expiry_date: string | null;
    issue_date: string | null;
    size_bytes: number | null;
    expiry_status: DocumentExpiryStatus | null;
    remaining_days: number | null;
    expiry_label: string;
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
