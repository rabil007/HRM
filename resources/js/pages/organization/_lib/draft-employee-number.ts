/**
 * Provisional numbers created by ensureEmployee / CreateEmployeeFromName
 * (e.g. DRAFT-FEIXOIU9) before an official employee number is assigned.
 */
export function isDraftEmployeeNumber(value: unknown): boolean {
    if (value === null || value === undefined) {
        return false;
    }

    return /^DRAFT-[A-Z0-9]+$/i.test(String(value).trim());
}

export function isOfficialEmployeeNumberMissing(value: unknown): boolean {
    const trimmed = String(value ?? '').trim();

    return trimmed === '' || isDraftEmployeeNumber(trimmed);
}
