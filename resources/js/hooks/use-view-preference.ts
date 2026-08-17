import { useSyncExternalStore } from 'react';

export type ViewPreference = 'grid' | 'list' | 'employee' | 'tree';

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

const MOBILE_CARD_VIEW_KEYS = new Set<string>([
    ...ORGANIZATION_VIEW_KEYS,
    'employees:view',
]);

function mobileSafeView(
    storageKey: string,
    view: ViewPreference,
): ViewPreference {
    if (
        typeof window !== 'undefined' &&
        MOBILE_CARD_VIEW_KEYS.has(storageKey) &&
        window.matchMedia('(max-width: 767px)').matches &&
        view === 'list'
    ) {
        return 'grid';
    }

    return view;
}

export function setOrganizationDefaultView(next: ViewPreference): void {
    localStorage.setItem(DEFAULT_VIEW_STORAGE_KEY, next);

    for (const key of ORGANIZATION_VIEW_KEYS) {
        localStorage.setItem(key, next);
    }

    notify();
}

export function useViewPreference(
    storageKey: string,
    defaultValue: ViewPreference = 'grid',
) {
    const isValid = (v: string | null): v is ViewPreference =>
        v === 'grid' || v === 'list' || v === 'employee' || v === 'tree';

    const getSnapshot = (): ViewPreference => {
        if (typeof window === 'undefined') {
            return defaultValue;
        }

        const stored = localStorage.getItem(storageKey);

        if (isValid(stored)) {
            return mobileSafeView(storageKey, stored);
        }

        const globalDefault = localStorage.getItem(DEFAULT_VIEW_STORAGE_KEY);

        if (isValid(globalDefault)) {
            return mobileSafeView(storageKey, globalDefault);
        }

        return mobileSafeView(storageKey, defaultValue);
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
