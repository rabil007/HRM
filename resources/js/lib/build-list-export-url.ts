export function buildListExportUrl(
    basePath: string,
    params: Record<string, string | number | boolean | null | undefined>,
): string {
    const searchParams = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (value === true) {
            searchParams.set(key, '1');
            continue;
        }

        if (typeof value === 'string' && value.trim() === '') {
            continue;
        }

        searchParams.set(key, String(value));
    }

    const query = searchParams.toString();

    return query ? `${basePath}?${query}` : basePath;
}
