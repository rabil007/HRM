import type { BulkAddCrewFormData } from '../types.ts';

const STORAGE_KEY = 'oms-hrm:bulk-create-session';
const SESSION_TTL_MS = 2 * 60 * 60 * 1000;

export type BulkCreateSessionSnapshot = {
    companyId: number;
    savedAt: number;
    expiresAt: number;
    form: Pick<
        BulkAddCrewFormData,
        'client_id' | 'vessel_id' | 'planned_join_at' | 'remarks' | 'crew'
    >;
    rowKeys: string[];
    bulkSidebarMode: 'summary' | 'preview';
    previewRowKey: string | null;
};

function sessionStorageAvailable(): Storage | null {
    return typeof globalThis.sessionStorage === 'undefined'
        ? null
        : globalThis.sessionStorage;
}

export function saveBulkCreateSession(
    companyId: number,
    snapshot: Omit<
        BulkCreateSessionSnapshot,
        'companyId' | 'savedAt' | 'expiresAt'
    >,
): void {
    const storage = sessionStorageAvailable();

    if (storage === null) {
        return;
    }

    const savedAt = Date.now();
    const payload: BulkCreateSessionSnapshot = {
        companyId,
        savedAt,
        expiresAt: savedAt + SESSION_TTL_MS,
        ...snapshot,
    };

    storage.setItem(STORAGE_KEY, JSON.stringify(payload));
}

export function loadBulkCreateSession(
    companyId: number,
): BulkCreateSessionSnapshot | null {
    const storage = sessionStorageAvailable();

    if (storage === null) {
        return null;
    }

    const raw = storage.getItem(STORAGE_KEY);

    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as BulkCreateSessionSnapshot;

        if (
            parsed.companyId !== companyId ||
            parsed.expiresAt <= Date.now() ||
            !Array.isArray(parsed.rowKeys) ||
            !Array.isArray(parsed.form?.crew)
        ) {
            storage.removeItem(STORAGE_KEY);

            return null;
        }

        return parsed;
    } catch {
        storage.removeItem(STORAGE_KEY);

        return null;
    }
}

export function clearBulkCreateSession(): void {
    sessionStorageAvailable()?.removeItem(STORAGE_KEY);
}
