const LOG_PREFIX = '[OMS-HRM:template-fields]';

export function logTemplateFieldsDebug(
    enabled: boolean | undefined,
    context: string,
    data: Record<string, unknown>,
): void {
    if (!enabled) {
        return;
    }

    console.info(LOG_PREFIX, context, data);
}
