import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

/**
 * When `templateFields` is null/undefined, every field is shown (no template assigned).
 */
export function isTemplateFieldVisible(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    fieldKey: string,
): boolean {
    if (!templateFields) {
        return true;
    }

    return templateFields[fieldKey]?.visible === true;
}

export function createTemplateFieldVisibility(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
): (fieldKey: string) => boolean {
    return (fieldKey: string) => isTemplateFieldVisible(templateFields, fieldKey);
}

export function isTemplateFieldRequired(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    fieldKey: string,
    defaultRequiredKeys: string[] = [],
): boolean {
    if (!templateFields) {
        return defaultRequiredKeys.includes(fieldKey);
    }

    const config = templateFields[fieldKey];

    return config?.visible === true && config?.required === true;
}

export function getTemplateRequiredFieldKeys(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    defaultRequiredKeys: string[] = [],
): Set<string> {
    if (!templateFields) {
        return new Set(defaultRequiredKeys);
    }

    const keys = new Set<string>();

    for (const [fieldKey, config] of Object.entries(templateFields)) {
        if (config.visible && config.required) {
            keys.add(fieldKey);
        }
    }

    return keys;
}

export function isEmptyTemplateFieldValue(value: unknown): boolean {
    return String(value ?? '').trim() === '';
}
