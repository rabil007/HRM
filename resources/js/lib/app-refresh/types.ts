export type AppRefreshShared = {
    version: string;
    authorization_revision: string | null;
};

export type AppVersionResponse = {
    version: string;
    update_available: boolean;
    authorization_revision: string | null;
};

export type AppRefreshSnapshot = {
    /** Version of the JS/HTML currently loaded in this browser tab. */
    loadedVersion: string;
    /** Newest version reported by the server, when different from loaded. */
    pendingVersion: string | null;
    authorizationRevision: string | null;
    /** True when a newer deploy or waiting SW is known (even if dialog was dismissed). */
    updateAvailable: boolean;
    /** True when the user chose Later for the current pending version. */
    updateDismissed: boolean;
    updateDismissedVersion: string | null;
    pwaUpdateWaiting: boolean;
    refreshing: boolean;
    lastError: string | null;
};

export type AppRefreshListener = (snapshot: AppRefreshSnapshot) => void;
