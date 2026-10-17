export type DocumentAiCompanyMode = 'off' | 'optional' | 'automatic';
export type DocumentAiExtractionPreferenceValue = 'on' | 'off';

export function documentAiExtractionPreferenceStorageKey(
    userId: number,
): string {
    return `oms-hrm:document-ai-extraction:${userId}`;
}

export function parseDocumentAiExtractionPreference(
    raw: string | null,
): DocumentAiExtractionPreferenceValue | null {
    if (raw === 'on' || raw === 'off') {
        return raw;
    }

    return null;
}

/**
 * Resolve whether the user wants AI extraction enabled.
 * Unset preferences default ON in company automatic mode (preserves auto-start)
 * and OFF in optional mode (user opts in via the upload toggle).
 */
export function resolveDocumentAiExtractionEnabled(
    stored: DocumentAiExtractionPreferenceValue | null,
    companyMode: DocumentAiCompanyMode,
): boolean {
    if (companyMode === 'off') {
        return false;
    }

    if (stored === 'on') {
        return true;
    }

    if (stored === 'off') {
        return false;
    }

    return companyMode === 'automatic';
}

/**
 * Company settings + upload capability + user preference must all allow AI
 * before any extract request is initiated.
 */
export function isDocumentAiExtractionActive(options: {
    canUpload: boolean;
    companyMode: DocumentAiCompanyMode;
    providerAvailable: boolean;
    userPreferenceEnabled: boolean;
}): boolean {
    return (
        options.canUpload &&
        options.companyMode !== 'off' &&
        options.providerAvailable &&
        options.userPreferenceEnabled
    );
}
