import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppUpdateDialog } from '@/components/app-update-dialog';
import { appRefreshController } from '@/lib/app-refresh/app-refresh-controller';
import { captureLoadedAppVersion } from '@/lib/app-refresh/loaded-version';
import type { AppRefreshShared } from '@/lib/app-refresh/types';

type PageProps = {
    auth?: { user?: { id?: number } | null };
    app_refresh?: AppRefreshShared;
};

/**
 * Boots the singleton app-refresh controller for authenticated sessions.
 * Loaded frontend version is captured once per document load and is never
 * replaced by later Inertia shared-prop versions.
 */
export function AppRefreshSync({ versionUrl }: { versionUrl: string }) {
    const page = usePage<PageProps>();
    const userId = page.props.auth?.user?.id ?? null;
    const appRefresh = page.props.app_refresh;
    const sharedVersion = appRefresh?.version;
    const sharedAuthorizationRevision =
        appRefresh?.authorization_revision ?? null;

    useEffect(() => {
        if (!userId || !sharedVersion) {
            appRefreshController.stop();

            return;
        }

        const loadedVersion = captureLoadedAppVersion(sharedVersion);

        appRefreshController.start({
            versionUrl,
            userId,
            loadedVersion,
            initialAuthorizationRevision: sharedAuthorizationRevision,
        });
    }, [userId, versionUrl, sharedVersion, sharedAuthorizationRevision]);

    useEffect(() => {
        if (!userId) {
            appRefreshController.stop();

            return;
        }

        if (!sharedVersion) {
            return;
        }

        appRefreshController.syncFromInertiaProps(
            sharedVersion,
            sharedAuthorizationRevision,
        );
    }, [userId, sharedVersion, sharedAuthorizationRevision]);

    if (!userId) {
        return null;
    }

    return <AppUpdateDialog />;
}
