import type { ReactNode } from 'react';
import type { PaginationMeta } from '@/types/pagination';

export type EntityDocumentType = {
    id: number;
    title: string;
};

export type EntityDocument = {
    id: number;
    title: string;
    document_type: EntityDocumentType | null;
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

export type EntityDocumentOwner = {
    id: number;
    name: string;
    subtitle?: string | null;
    logo_url?: string | null;
};

export type EntityDocumentPermissions = {
    view: boolean;
    upload: boolean;
    update: boolean;
    download: boolean;
    delete: boolean;
    manage_notifications?: boolean;
};

export type EntityDocumentSummary = {
    total: number;
    valid: number;
    expiring_soon: number;
    expired: number;
};

export type DocumentWorkspaceRoutes = {
    pageUrl: string;
    store: string;
    bulkStore: string;
    update: (documentId: number) => string;
    destroy: (documentId: number) => string;
    replace: (documentId: number) => string;
    versionsIndex: (documentId: number) => string;
};

export type DocumentWorkspaceProps = {
    owner: EntityDocumentOwner;
    ownerType: 'company' | 'branch';
    pageUrl: string;
    backHref: string;
    backLabel: string;
    title: string;
    description: string;
    routes: DocumentWorkspaceRoutes;
    permissions: EntityDocumentPermissions;
    documents: EntityDocument[];
    pagination: PaginationMeta;
    filters: {
        search: string;
        document_type: number | null;
        expiry_status: string;
    };
    summary: EntityDocumentSummary;
    documentTypes: EntityDocumentType[];
    headerActionsExtra?: ReactNode;
    bannerNotice?: ReactNode;
    emptyTitle?: string;
};
