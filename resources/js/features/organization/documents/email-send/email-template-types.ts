import { templateBodyToMessage } from '@/features/organization/documents/email-send/email-utils';
import { joinEmailList, parseEmailList } from '@/features/organization/documents/email-send/parse-email-list';

export type EmailTemplateOption = {
    slug: string;
    label: string;
    to_preset: string | null;
    cc_preset: string | null;
    subject: string;
    body_html: string;
    is_default: boolean;
};

export const CUSTOM_EMAIL_TEMPLATE_VALUE = '__custom__';

export function resolveDefaultEmailTemplate(
    templates: EmailTemplateOption[],
): EmailTemplateOption | null {
    if (templates.length === 0) {
        return null;
    }

    return templates.find((template) => template.is_default) ?? templates[0];
}

export function applyEmailTemplateToFields(
    template: EmailTemplateOption,
    employeeEmail: string,
): {
    subject: string;
    message: string;
    recipient: string;
    cc: string;
} {
    const toEmails = parseEmailList(template.to_preset);
    const ccEmails = parseEmailList(template.cc_preset);

    let recipient = employeeEmail.trim();
    let ccParts = [...ccEmails];

    if (toEmails.length > 0) {
        recipient = toEmails[0];
        ccParts = [...toEmails.slice(1), ...ccParts];
    }

    return {
        subject: template.subject,
        message: templateBodyToMessage(template.body_html),
        recipient,
        cc: joinEmailList(ccParts),
    };
}
