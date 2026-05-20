import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';

export type MergeDocumentItem = Pick<
    DocumentBrowseItem,
    'id' | 'document_name' | 'file_url' | 'size_bytes' | 'mime_type'
>;

export type PdfPreviewData = {
    pageCount: number;
    thumbnailDataUrl: string | null;
};
