/** Split a comma-separated email list and trim empty entries. */
export function parseEmailList(raw: string | null | undefined): string[] {
    if (raw == null || raw.trim() === '') {
        return [];
    }

    return raw
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '');
}

/** Join unique emails (case-insensitive) with ", ". */
export function joinEmailList(emails: string[]): string {
    const seen = new Set<string>();
    const unique: string[] = [];

    for (const email of emails) {
        const key = email.toLowerCase();

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(email);
    }

    return unique.join(', ');
}
