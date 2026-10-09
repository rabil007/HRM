/**
 * Pure helpers for loaded-vs-server version comparison.
 * Kept separate so unit tests do not need the singleton controller.
 */

export function resolveUpdateAvailability(options: {
    loadedVersion: string;
    serverVersion: string | null;
    pwaUpdateWaiting: boolean;
}): {
    pendingVersion: string | null;
    updateAvailable: boolean;
} {
    const pendingVersion =
        options.serverVersion &&
        options.serverVersion !== '' &&
        options.serverVersion !== options.loadedVersion
            ? options.serverVersion
            : null;

    return {
        pendingVersion,
        updateAvailable: pendingVersion !== null || options.pwaUpdateWaiting,
    };
}

export function shouldAutoOpenUpdateDialog(options: {
    updateAvailable: boolean;
    pendingVersion: string | null;
    dismissedVersion: string | null;
    pwaUpdateWaiting: boolean;
    force: boolean;
}): boolean {
    if (!options.updateAvailable) {
        return false;
    }

    if (options.force || options.pwaUpdateWaiting) {
        return true;
    }

    if (
        options.pendingVersion !== null &&
        options.dismissedVersion === options.pendingVersion
    ) {
        return false;
    }

    return true;
}
