import { formatBytes } from '@/lib/utils';

import type { EmailDocumentItem } from '@/features/organization/documents/email-send/types';
import { EMAIL_MAX_ATTACHMENT_BYTES } from '@/features/organization/documents/email-send/types';

export function buildDefaultEmailSubject(employeeName: string, organizationName: string): string {
    return `${employeeName} - Documents from ${organizationName}`;
}

export function totalAttachmentBytes(documents: EmailDocumentItem[]): number {
    return documents.reduce((total, document) => total + (document.size_bytes ?? 0), 0);
}

export function isAttachmentSizeExceeded(documents: EmailDocumentItem[]): boolean {
    return totalAttachmentBytes(documents) > EMAIL_MAX_ATTACHMENT_BYTES;
}

export function emailMaxAttachmentLabel(): string {
    return formatBytes(EMAIL_MAX_ATTACHMENT_BYTES);
}
