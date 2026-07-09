import type { WiredEmailTemplate } from '@/features/organization/documents/bulk/types';
import { parseEmailList } from '@/features/organization/documents/email-send/parse-email-list';

export type BulkEmailPreviewEmployee = {
    name: string;
    employee_no: string | null;
    email: string | null;
};

export function dedupeEmails(emails: string[]): string[] {
    const seen = new Set<string>();
    const unique: string[] = [];

    for (const email of emails) {
        const key = email.toLowerCase();

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(email);
    }

    return unique;
}

export function initialCcFromTemplate(template: WiredEmailTemplate): string[] {
    const toEmails = parseEmailList(template.to_preset);
    const ccEmails = parseEmailList(template.cc_preset);

    return dedupeEmails([...toEmails.slice(1), ...ccEmails]);
}

export function substituteBulkEmailTemplate(
    template: string,
    employee: BulkEmailPreviewEmployee,
    companyName: string,
    documentTypeLabel: string,
    signatureUrl = 'https://example.com/sign/sample-link',
): string {
    return template
        .replaceAll('{{employee_name}}', employee.name)
        .replaceAll('{{employee_no}}', employee.employee_no ?? '')
        .replaceAll('{{company_name}}', companyName)
        .replaceAll('{{document_type}}', documentTypeLabel)
        .replaceAll('{{signature_url}}', signatureUrl);
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/** Render stored template body for the bulk email preview panel. */
export function formatBulkEmailBodyPreview(body: string): string {
    const trimmed = body.trim();

    if (trimmed === '') {
        return '';
    }

    if (/<[a-z][\s\S]*>/i.test(trimmed)) {
        return trimmed;
    }

    return trimmed
        .split(/\n{2,}/)
        .filter((paragraph) => paragraph.trim() !== '')
        .map(
            (paragraph) =>
                `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`,
        )
        .join('');
}

export function isValidEmailAddress(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
}
