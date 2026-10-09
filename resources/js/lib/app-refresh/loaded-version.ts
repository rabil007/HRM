/**
 * Capture the application version from the first authenticated document load.
 * Never replaced by later Inertia shared props until a hard navigation/reload.
 */
let documentLoadedVersion: string | null = null;

export function captureLoadedAppVersion(version: string): string {
    if (documentLoadedVersion === null || documentLoadedVersion === '') {
        documentLoadedVersion = version;
    }

    return documentLoadedVersion;
}

export function getLoadedAppVersion(): string | null {
    return documentLoadedVersion;
}

/** Test helper only. */
export function resetLoadedAppVersion(): void {
    documentLoadedVersion = null;
}
