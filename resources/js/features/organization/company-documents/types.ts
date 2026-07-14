import type { PaginationMeta } from '@/types/pagination';

export type CompanyDocumentCompany = {
    id: number;
    name: string;
    logo_url: string | null;
};

export type CompanyDocumentType = { id: number; title: string };

export type CompanyDocument = {
    id: number;
    title: string;
    document_type: CompanyDocumentType | null;
    document_number: string | null;
    issue_date: string | null;
    expiry_date: string | null;
    expiry_status: string;
    expiry_label: string;
    remaining_days: number | null;
    notes: string | null;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    current_version: number;
    can_preview: boolean;
    uploaded_by: string | null;
    uploaded_at: string | null;
    replaced_at: string | null;
    preview_url: string;
    download_url: string;
};

export type CompanyDocumentPermissions = {
    view: boolean;
    upload: boolean;
    update: boolean;
    download: boolean;
    delete: boolean;
};

export type CompanyDocumentsPageProps = {
    company: CompanyDocumentCompany;
    documents: CompanyDocument[];
    pagination: PaginationMeta;
    filters: {
        search: string;
        document_type: number | null;
        expiry_status: string;
    };
    summary: {
        total: number;
        valid: number;
        expiring_soon: number;
        expired: number;
    };
    document_types: CompanyDocumentType[];
    can: CompanyDocumentPermissions;
};
