import { useSyncExternalStore } from 'react';

const standaloneDisplayModeQuery = '(display-mode: standalone)';

type StandalonePwaEnvironment = {
    matchMedia?: (query: string) => { matches: boolean };
    navigator?: {
        readonly standalone?: boolean;
    };
};

function browserEnvironment(): StandalonePwaEnvironment | undefined {
    if (typeof window === 'undefined') {
        return undefined;
    }

    return {
        matchMedia:
            typeof window.matchMedia === 'function'
                ? window.matchMedia.bind(window)
                : undefined,
        navigator: window.navigator as Navigator & {
            readonly standalone?: boolean;
        },
    };
}

function matchesStandaloneDisplayMode(
    matchMedia: StandalonePwaEnvironment['matchMedia'],
): boolean {
    if (typeof matchMedia !== 'function') {
        return false;
    }

    try {
        return matchMedia(standaloneDisplayModeQuery).matches === true;
    } catch {
        return false;
    }
}

export function detectStandalonePwa(
    environment: StandalonePwaEnvironment | undefined,
): boolean {
    if (!environment) {
        return false;
    }

    return (
        matchesStandaloneDisplayMode(environment.matchMedia) ||
        environment.navigator?.standalone === true
    );
}

function getStandalonePwaSnapshot(): boolean {
    return detectStandalonePwa(browserEnvironment());
}

function subscribeToStandalonePwa(onStoreChange: () => void): () => void {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return () => undefined;
    }

    try {
        const displayMode = window.matchMedia(standaloneDisplayModeQuery);

        if (typeof displayMode.addEventListener === 'function') {
            displayMode.addEventListener('change', onStoreChange);

            return () =>
                displayMode.removeEventListener('change', onStoreChange);
        }

        if (typeof displayMode.addListener === 'function') {
            displayMode.addListener(onStoreChange);

            return () => displayMode.removeListener(onStoreChange);
        }
    } catch {
        return () => undefined;
    }

    return () => undefined;
}

export function useIsStandalonePwa(): boolean {
    return useSyncExternalStore(
        subscribeToStandalonePwa,
        getStandalonePwaSnapshot,
        () => false,
    );
}
