import type { ShareLinkDocument } from '@/features/organization/documents/whatsapp-share/types';

export function buildWhatsAppMessage(
    employeeName: string,
    documents: ShareLinkDocument[],
): string {
    const lines = [`Employee Documents — ${employeeName}`, ''];

    for (const document of documents) {
        lines.push(document.name);
        lines.push(document.share_url);
        lines.push('');
    }

    return lines.join('\n').trimEnd();
}
