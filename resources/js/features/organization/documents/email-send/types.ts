import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';

export type EmailDocumentItem = Pick<
    DocumentBrowseItem,
    'id' | 'document_name' | 'mime_type' | 'size_bytes'
>;

export type EmailDocumentsPayload = {
    document_ids: number[];
    recipient: string;
    cc?: string;
    subject: string;
    message?: string;
};

export const EMAIL_MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;
