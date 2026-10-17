import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { update as updateDocumentAiExtractionPreference } from '@/actions/App/Http/Controllers/Organization/DocumentAiExtractionPreferenceController';
import {
    documentAiExtractionPreferenceStorageKey,
    parseDocumentAiExtractionPreference,
    resolveDocumentAiExtractionEnabled,
} from '@/features/organization/documents/lib/document-ai-extraction-preference';
import type {
    DocumentAiCompanyMode,
    DocumentAiExtractionPreferenceValue,
} from '@/features/organization/documents/lib/document-ai-extraction-preference';
import type { Auth } from '@/types';

function readLegacyLocalPreference(
    userId: number | null,
): DocumentAiExtractionPreferenceValue | null {
    if (userId === null || typeof localStorage === 'undefined') {
        return null;
    }

    try {
        return parseDocumentAiExtractionPreference(
            localStorage.getItem(
                documentAiExtractionPreferenceStorageKey(userId),
            ),
        );
    } catch {
        return null;
    }
}

function clearLegacyLocalPreference(userId: number): void {
    if (typeof localStorage === 'undefined') {
        return;
    }

    try {
        localStorage.removeItem(
            documentAiExtractionPreferenceStorageKey(userId),
        );
    } catch {
        // Private mode / blocked storage — best-effort only.
    }
}

/**
 * Persists the Document AI extraction ON/OFF preference per authenticated user
 * on the server (shared Inertia prop), matching navigation-favorites style UX
 * prefs. Migrates a one-time legacy localStorage value when the server value
 * is still unset.
 */
export function useDocumentAiExtractionPreference(
    companyMode: DocumentAiCompanyMode,
) {
    const page = usePage();
    const userId = (page.props.auth as Auth | undefined)?.user?.id ?? null;
    const sharedPreference = parseDocumentAiExtractionPreference(
        page.props.document_ai_extraction_preference ?? null,
    );
    const [pendingEnabled, setPendingEnabled] = useState<boolean | null>(null);
    const migratedRef = useRef(false);

    const legacyPreference =
        sharedPreference === null ? readLegacyLocalPreference(userId) : null;
    const storedPreference = sharedPreference ?? legacyPreference;
    const resolvedEnabled = resolveDocumentAiExtractionEnabled(
        storedPreference,
        companyMode,
    );
    const enabled =
        pendingEnabled !== null && pendingEnabled !== resolvedEnabled
            ? pendingEnabled
            : resolvedEnabled;

    useEffect(() => {
        if (
            migratedRef.current ||
            userId === null ||
            sharedPreference !== null ||
            legacyPreference === null
        ) {
            return;
        }

        migratedRef.current = true;

        router.put(
            updateDocumentAiExtractionPreference.url(),
            { enabled: legacyPreference === 'on' },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    clearLegacyLocalPreference(userId);
                },
                onError: () => {
                    migratedRef.current = false;
                },
            },
        );
    }, [legacyPreference, sharedPreference, userId]);

    const setEnabled = (next: boolean): void => {
        if (userId === null) {
            return;
        }

        setPendingEnabled(next);

        router.put(
            updateDocumentAiExtractionPreference.url(),
            { enabled: next },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setPendingEnabled(null);
                },
                onError: () => {
                    setPendingEnabled(null);
                },
            },
        );
    };

    return [enabled, setEnabled] as const;
}
