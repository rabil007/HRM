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
    clientVersion: string;
    authorizationRevision: string | null;
    updateAvailable: boolean;
    updateDismissedVersion: string | null;
    pwaUpdateWaiting: boolean;
    refreshing: boolean;
    lastError: string | null;
};

export type AppRefreshListener = (snapshot: AppRefreshSnapshot) => void;
