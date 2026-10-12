import { useSyncExternalStore } from 'react';

export type CandidatesViewPreference = 'table' | 'kanban';

export const CANDIDATES_VIEW_STORAGE_KEY = 'recruitment-candidates:view';

const listeners = new Set<() => void>();

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => {
        listeners.delete(callback);
    };
};

const notify = (): void => listeners.forEach((listener) => listener());

const isValid = (value: string | null): value is CandidatesViewPreference =>
    value === 'table' || value === 'kanban';

function readStoredPreference(
    defaultValue: CandidatesViewPreference,
): CandidatesViewPreference {
    if (typeof window === 'undefined') {
        return defaultValue;
    }

    try {
        const stored = localStorage.getItem(CANDIDATES_VIEW_STORAGE_KEY);

        return isValid(stored) ? stored : defaultValue;
    } catch {
        return defaultValue;
    }
}

export function getPinnedCandidatesView(
    defaultValue: CandidatesViewPreference = 'table',
): CandidatesViewPreference {
    return readStoredPreference(defaultValue);
}

export function pinCandidatesView(next: CandidatesViewPreference): void {
    try {
        localStorage.setItem(CANDIDATES_VIEW_STORAGE_KEY, next);
        notify();
    } catch {
        // Private mode / blocked storage — preference is best-effort only.
    }
}

/**
 * Reads the pinned default view. Toggle does not write — only `pinCandidatesView` does.
 */
export function useCandidatesViewPreference(
    defaultValue: CandidatesViewPreference = 'table',
) {
    const getSnapshot = (): CandidatesViewPreference =>
        readStoredPreference(defaultValue);

    const view = useSyncExternalStore(
        subscribe,
        getSnapshot,
        () => defaultValue,
    );

    return [view, pinCandidatesView] as const;
}
