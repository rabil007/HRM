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

export function detectStandalonePwa(
    environment: StandalonePwaEnvironment | undefined,
): boolean {
    if (!environment) {
        return false;
    }

    const matchesStandaloneDisplayMode =
        environment.matchMedia?.(standaloneDisplayModeQuery).matches ?? false;
    const isIosStandalone = environment.navigator?.standalone === true;

    return matchesStandaloneDisplayMode || isIosStandalone;
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

    const displayMode = window.matchMedia(standaloneDisplayModeQuery);

    if (typeof displayMode.addEventListener === 'function') {
        displayMode.addEventListener('change', onStoreChange);

        return () => displayMode.removeEventListener('change', onStoreChange);
    }

    if (typeof displayMode.addListener === 'function') {
        displayMode.addListener(onStoreChange);

        return () => displayMode.removeListener(onStoreChange);
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
