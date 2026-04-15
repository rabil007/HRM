import { useSyncExternalStore } from 'react';

export type ViewPreference = 'grid' | 'list';

const DEFAULT_VIEW_STORAGE_KEY = 'view:default';

const listeners = new Set<() => void>();

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const ORGANIZATION_VIEW_KEYS = [
    'companies:view',
    'branches:view',
    'departments:view',
    'positions:view',
    'users:view',
    'roles:view',
] as const;

export function setOrganizationDefaultView(next: ViewPreference): void {
    localStorage.setItem(DEFAULT_VIEW_STORAGE_KEY, next);

    for (const key of ORGANIZATION_VIEW_KEYS) {
        localStorage.setItem(key, next);
    }

    notify();
}

export function useViewPreference(storageKey: string, defaultValue: ViewPreference = 'grid') {
    const getSnapshot = (): ViewPreference => {
        if (typeof window === 'undefined') {
            return defaultValue;
        }

        const stored = localStorage.getItem(storageKey) as ViewPreference | null;

        if (stored === 'grid' || stored === 'list') {
            return stored;
        }

        const globalDefault = localStorage.getItem(DEFAULT_VIEW_STORAGE_KEY) as ViewPreference | null;

        if (globalDefault === 'grid' || globalDefault === 'list') {
            return globalDefault;
        }

        return defaultValue;
    };

    const setView = (next: ViewPreference) => {
        localStorage.setItem(storageKey, next);
        notify();
    };

    const view = useSyncExternalStore(
        subscribe,
        getSnapshot,
        () => defaultValue,
    );

    return [view, setView] as const;
}

