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

    const config = templateFields[fieldKey];

    if (!config) {
        return true;
    }

    return config.visible === true;
}

export function createTemplateFieldVisibility(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
): (fieldKey: string) => boolean {
    return (fieldKey: string) =>
        isTemplateFieldVisible(templateFields, fieldKey);
}

export function isTemplateFieldRequired(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    fieldKey: string,
    defaultRequiredKeys: string[] = [],
): boolean {
    if (!isTemplateFieldVisible(templateFields, fieldKey)) {
        return false;
    }

    if (!templateFields) {
        return defaultRequiredKeys.includes(fieldKey);
    }

    const config = templateFields[fieldKey];

    if (!config) {
        return defaultRequiredKeys.includes(fieldKey);
    }

    return config.required === true;
}

export function getTemplateRequiredFieldKeys(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    defaultRequiredKeys: string[] = [],
): Set<string> {
    if (!templateFields) {
        return new Set(defaultRequiredKeys);
    }

    const keys = new Set<string>();

    for (const fieldKey of Object.keys(templateFields)) {
        if (
            isTemplateFieldRequired(
                templateFields,
                fieldKey,
                defaultRequiredKeys,
            )
        ) {
            keys.add(fieldKey);
        }
    }

    for (const fieldKey of defaultRequiredKeys) {
        if (
            isTemplateFieldRequired(
                templateFields,
                fieldKey,
                defaultRequiredKeys,
            )
        ) {
            keys.add(fieldKey);
        }
    }

    return keys;
}

export function isEmptyTemplateFieldValue(value: unknown): boolean {
    if (value instanceof File) {
        return false;
    }

    return String(value ?? '').trim() === '';
}

/**
 * Drop record payload keys hidden by the assigned template. Request keys may
 * differ from template registry keys (e.g. certificate vs certificate_path).
 */
export function omitHiddenTemplateRecordFields(
    payload: Record<string, unknown>,
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    requestFieldAliases: Record<string, string> = {},
): Record<string, unknown> {
    if (!templateFields) {
        return payload;
    }

    const result = { ...payload };

    for (const [templateFieldKey, config] of Object.entries(templateFields)) {
        if (config.visible) {
            continue;
        }

        delete result[templateFieldKey];

        for (const [requestKey, mappedTemplateKey] of Object.entries(
            requestFieldAliases,
        )) {
            if (mappedTemplateKey === templateFieldKey) {
                delete result[requestKey];
            }
        }
    }

    return result;
}
