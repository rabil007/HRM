export type WhatsAppTemplateOption = {
    slug: string;
    label: string;
    meta_name: string;
    is_default: boolean;
};

export function resolveDefaultWhatsAppTemplate(
    templates: WhatsAppTemplateOption[],
): WhatsAppTemplateOption | null {
    if (templates.length === 0) {
        return null;
    }

    return templates.find((template) => template.is_default) ?? templates[0];
}
