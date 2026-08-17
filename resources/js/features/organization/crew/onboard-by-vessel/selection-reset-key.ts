const IGNORED_FILTER_KEYS = new Set([
    'page',
    'view',
    'from',
    'to',
    'per_page',
    'format',
    'scope',
]);

export function onboardSelectionResetKey({
    companyId,
    search,
    filters,
}: {
    companyId: number | string | null | undefined;
    search: string;
    filters: object;
}): string {
    const filterSignature = Object.entries(filters as Record<string, unknown>)
        .filter(([key]) => !IGNORED_FILTER_KEYS.has(key))
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([key, value]) => `${key}:${String(value ?? '')}`)
        .join(',');

    return `${String(companyId ?? '')}|${search.trim()}|${filterSignature}`;
}
