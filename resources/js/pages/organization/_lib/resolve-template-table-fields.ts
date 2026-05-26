import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

export function resolveTemplateTableFields(
    employeeTabsFields: Record<string, Record<string, TemplateFieldConfig>> | undefined,
    resolvedTemplateFields: Record<string, Record<string, TemplateFieldConfig>> | undefined,
    table: string,
): Record<string, TemplateFieldConfig> | null {
    return employeeTabsFields?.[table] ?? resolvedTemplateFields?.[table] ?? null;
}
