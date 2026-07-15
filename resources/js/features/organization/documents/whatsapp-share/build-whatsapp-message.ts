import type { ShareLinkDocument } from '@/features/organization/documents/whatsapp-share/types';

export function buildWhatsAppMessage(
    employeeName: string,
    documents: ShareLinkDocument[],
    shareUrl: string,
): string {
    const lines = [`Employee Documents — ${employeeName}`, ''];

    for (const document of documents) {
        lines.push(`• ${document.name}`);
    }

    if (documents.length > 0) {
        lines.push('');
    }

    lines.push(shareUrl);

    return lines.join('\n').trimEnd();
}

export function buildFolderWhatsAppMessage(
    shares: { name: string; share_url: string }[],
): string {
    const lines = ['Shared document folders', ''];

    for (const share of shares) {
        lines.push(share.name);
        lines.push(share.share_url);
        lines.push('');
    }

    return lines.join('\n').trimEnd();
}
