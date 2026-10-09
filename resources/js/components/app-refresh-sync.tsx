import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppUpdateDialog } from '@/components/app-update-dialog';
import { appRefreshController } from '@/lib/app-refresh/app-refresh-controller';
import type { AppRefreshShared } from '@/lib/app-refresh/types';

type PageProps = {
    auth?: { user?: { id?: number } | null };
    app_refresh?: AppRefreshShared;
};

/**
 * Boots the singleton app-refresh controller for authenticated sessions and
 * keeps its local revision/version in sync with Inertia shared props.
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
            return;
        }

        appRefreshController.start({
            versionUrl,
            initialVersion: sharedVersion,
            initialAuthorizationRevision: sharedAuthorizationRevision,
        });
    }, [userId, versionUrl, sharedVersion, sharedAuthorizationRevision]);

    useEffect(() => {
        if (!userId || !sharedVersion) {
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
