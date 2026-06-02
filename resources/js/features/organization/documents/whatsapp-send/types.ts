import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';

export type WhatsAppDocumentItem = Pick<
    DocumentBrowseItem,
    'id' | 'document_name' | 'mime_type' | 'size_bytes'
>;

export type WhatsAppDocumentsPayload = {
    document_ids: number[];
    whatsapp_number: string;
    send_template_first?: boolean;
};

export type WhatsAppDocumentSendResult = {
    document_id: number | null;
    document_name: string;
    success: boolean;
    status: string;
    message: string;
    message_id?: string | null;
    media_id?: string | null;
    http_status?: number | null;
    normalized_phone?: string | null;
    delivery_note?: string | null;
    error?: string | null;
};

export type WhatsAppDocumentsSendResponse = {
    message: string;
    sent_count: number;
    failed_count: number;
    results: WhatsAppDocumentSendResult[];
};
