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

export type DocumentBrowseItem = {
    id: number;
    document_name: string;
    document_type: string;
    file_url: string;
    uploaded_at: string | null;
    mime_type: string | null;
    can_preview: boolean;
    status: string | null;
};
