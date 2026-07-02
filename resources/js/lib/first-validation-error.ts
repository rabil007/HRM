export function firstValidationError(
    errors: Record<string, string | string[]>,
    key: string,
    fallback: string,
): string {
    const value = errors[key];

    if (Array.isArray(value)) {
        return value[0] ?? fallback;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        return value;
    }

    const first = Object.values(errors)[0];

    if (Array.isArray(first)) {
        return first[0] ?? fallback;
    }

    return typeof first === 'string' ? first : fallback;
}

export function hasFlashSuccess(page: { props?: unknown }): boolean {
    const flash = (page.props as { flash?: { success?: string } } | undefined)
        ?.flash;
    const success =
        typeof flash?.success === 'string' ? flash.success.trim() : '';

    return success !== '';
}
