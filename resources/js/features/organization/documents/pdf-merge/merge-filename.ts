function formatDateYmd(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}${month}${day}`;
}

export function buildDefaultMergeFilename(
    employeeName: string,
    date = new Date(),
): string {
    const segment =
        employeeName
            .trim()
            .replace(/[^a-zA-Z0-9-]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'EMPLOYEE';

    return `${segment.toUpperCase()}_DOCUMENTS_${formatDateYmd(date)}`;
}

export function sanitizeMergeFilename(value: string): string {
    return value
        .trim()
        .replace(/\.pdf$/i, '')
        .replace(/[^a-zA-Z0-9-_]+/g, '_')
        .replace(/^_+|_+$/g, '');
}
