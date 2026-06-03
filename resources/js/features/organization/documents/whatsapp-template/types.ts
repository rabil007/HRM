export type WhatsAppTemplateOption = {
    slug: string;
    label: string;
    meta_name: string;
    meta_language: string;
    category: string;
    category_label: string;
    body_preview: string;
    is_default: boolean;
};

export type WhatsAppTemplateCategoryGroup = {
    category: string;
    category_label: string;
    templates: WhatsAppTemplateOption[];
};

export function groupWhatsAppTemplatesByCategory(
    templates: WhatsAppTemplateOption[],
): WhatsAppTemplateCategoryGroup[] {
    const groups = new Map<string, WhatsAppTemplateCategoryGroup>();

    for (const template of templates) {
        const existing = groups.get(template.category);

        if (existing) {
            existing.templates.push(template);

            continue;
        }

        groups.set(template.category, {
            category: template.category,
            category_label: template.category_label,
            templates: [template],
        });
    }

    return Array.from(groups.values()).map((group) => ({
        ...group,
        templates: [...group.templates].sort((a, b) => {
            if (a.is_default !== b.is_default) {
                return a.is_default ? -1 : 1;
            }

            return a.label.localeCompare(b.label);
        }),
    }));
}

export function resolveDefaultWhatsAppTemplate(
    templates: WhatsAppTemplateOption[],
): WhatsAppTemplateOption | null {
    if (templates.length === 0) {
        return null;
    }

    return templates.find((template) => template.is_default) ?? templates[0];
}
