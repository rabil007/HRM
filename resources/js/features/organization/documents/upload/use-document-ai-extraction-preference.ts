import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
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

const listeners = new Set<() => void>();

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => {
        listeners.delete(callback);
    };
};

const notify = (): void => listeners.forEach((listener) => listener());

function readStoredPreference(
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

function writeStoredPreference(
    userId: number,
    next: DocumentAiExtractionPreferenceValue,
): void {
    try {
        localStorage.setItem(
            documentAiExtractionPreferenceStorageKey(userId),
            next,
        );
        notify();
    } catch {
        // Private mode / blocked storage — preference is best-effort only.
    }
}

/**
 * Persists the Document AI extraction ON/OFF preference per authenticated user
 * (localStorage), matching other UI preference hooks in the app.
 */
export function useDocumentAiExtractionPreference(
    companyMode: DocumentAiCompanyMode,
) {
    const userId = (usePage().props.auth as Auth | undefined)?.user?.id ?? null;

    const getSnapshot = (): boolean =>
        resolveDocumentAiExtractionEnabled(
            readStoredPreference(userId),
            companyMode,
        );

    const enabled = useSyncExternalStore(subscribe, getSnapshot, () =>
        resolveDocumentAiExtractionEnabled(null, companyMode),
    );

    const setEnabled = (next: boolean): void => {
        if (userId === null) {
            return;
        }

        writeStoredPreference(userId, next ? 'on' : 'off');
    };

    return [enabled, setEnabled] as const;
}
