
import type { EmailDocumentItem } from '@/features/organization/documents/email-send/types';
import { EMAIL_MAX_ATTACHMENT_BYTES } from '@/features/organization/documents/email-send/types';
import { formatBytes } from '@/lib/utils';

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

/** Normalize stored template body (plain text or legacy HTML) for the send modal. */
export function templateBodyToMessage(body: string): string {
    const trimmed = body.trim();

    if (trimmed === '') {
        return '';
    }

    if (!/<[a-z][\s\S]*>/i.test(trimmed)) {
        return trimmed;
    }

    const withBreaks = trimmed
        .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>/gi, '\n')
        .replace(/<p[^>]*>/gi, '');

    return withBreaks
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
