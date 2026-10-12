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

export function useCandidatesViewPreference(
    defaultValue: CandidatesViewPreference = 'table',
) {
    const getSnapshot = (): CandidatesViewPreference => {
        if (typeof window === 'undefined') {
            return defaultValue;
        }

        const stored = localStorage.getItem(CANDIDATES_VIEW_STORAGE_KEY);

        return isValid(stored) ? stored : defaultValue;
    };

    const setView = (next: CandidatesViewPreference) => {
        localStorage.setItem(CANDIDATES_VIEW_STORAGE_KEY, next);
        notify();
    };

    const view = useSyncExternalStore(
        subscribe,
        getSnapshot,
        () => defaultValue,
    );

    return [view, setView] as const;
}
