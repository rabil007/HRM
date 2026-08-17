import { useCallback, useEffect, useMemo, useState } from 'react';

export type WorkspaceShortcut = {
    id: string;
    title: string;
    href: string;
    subtitle?: string;
};

type StoredShortcuts = {
    favorites: WorkspaceShortcut[];
    recent: WorkspaceShortcut[];
    savedViews: WorkspaceShortcut[];
};

const emptyState: StoredShortcuts = {
    favorites: [],
    recent: [],
    savedViews: [],
};

function storageKey(userId: number | null, companyId: number | null): string {
    return `oms-hrm:workspace:${userId ?? 'guest'}:${companyId ?? 'none'}`;
}

function readState(key: string): StoredShortcuts {
    if (typeof window === 'undefined') {
        return emptyState;
    }

    try {
        const value = window.localStorage.getItem(key);

        if (!value) {
            return emptyState;
        }

        const parsed = JSON.parse(value) as Partial<StoredShortcuts>;

        return {
            favorites: Array.isArray(parsed.favorites) ? parsed.favorites : [],
            recent: Array.isArray(parsed.recent) ? parsed.recent : [],
            savedViews: Array.isArray(parsed.savedViews) ? parsed.savedViews : [],
        };
    } catch {
        return emptyState;
    }
}

export function useWorkspaceShortcuts(
    userId: number | null,
    companyId: number | null,
) {
    const key = useMemo(() => storageKey(userId, companyId), [companyId, userId]);
    const [state, setState] = useState<StoredShortcuts>(() => readState(key));

    useEffect(() => {
        setState(readState(key));
    }, [key]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        window.localStorage.setItem(key, JSON.stringify(state));
    }, [key, state]);

    const remember = useCallback((item: WorkspaceShortcut) => {
        setState((current) => ({
            ...current,
            recent: [
                item,
                ...current.recent.filter((entry) => entry.href !== item.href),
            ].slice(0, 8),
        }));
    }, []);

    const toggleFavorite = useCallback((item: WorkspaceShortcut) => {
        setState((current) => {
            const exists = current.favorites.some(
                (entry) => entry.href === item.href,
            );

            return {
                ...current,
                favorites: exists
                    ? current.favorites.filter(
                          (entry) => entry.href !== item.href,
                      )
                    : [item, ...current.favorites].slice(0, 12),
            };
        });
    }, []);

    const saveView = useCallback((item: WorkspaceShortcut) => {
        setState((current) => ({
            ...current,
            savedViews: [
                item,
                ...current.savedViews.filter(
                    (entry) => entry.href !== item.href,
                ),
            ].slice(0, 12),
        }));
    }, []);

    const removeSavedView = useCallback((href: string) => {
        setState((current) => ({
            ...current,
            savedViews: current.savedViews.filter(
                (entry) => entry.href !== href,
            ),
        }));
    }, []);

    const isFavorite = useCallback(
        (href: string) => state.favorites.some((entry) => entry.href === href),
        [state.favorites],
    );

    return {
        ...state,
        remember,
        toggleFavorite,
        saveView,
        removeSavedView,
        isFavorite,
    };
}
