/**
 * Strip grouping separators so Laravel's numeric validation accepts user input.
 */
export function normalizeDecimalFieldValue(value: string): string | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    return trimmed.replace(/,/g, '');
}
