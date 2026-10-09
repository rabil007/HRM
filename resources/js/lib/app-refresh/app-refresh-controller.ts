import { router } from '@inertiajs/react';
import { fetchAppVersion } from '@/lib/app-refresh/fetch-app-version';
import { hasUnsavedWork } from '@/lib/app-refresh/has-unsaved-work';
import { reloadApplication } from '@/lib/app-refresh/reload-application';
import type {
    AppRefreshListener,
    AppRefreshSnapshot,
} from '@/lib/app-refresh/types';
import {
    resolveUpdateAvailability,
    shouldAutoOpenUpdateDialog,
} from '@/lib/app-refresh/version-state';
import { toast } from '@/lib/toast';

/** Single poll covers auth (~30s) and deploy detection. */
const SYNC_POLL_MS = 30_000;

const DISMISS_STORAGE_KEY = 'oms-hrm:app-refresh:dismissed-version';

type ControllerOptions = {
    versionUrl: string;
    userId: number;
    /** Version baked into the currently loaded document/assets. */
    loadedVersion: string;
    initialAuthorizationRevision: string | null;
};

class AppRefreshController {
    private listeners = new Set<AppRefreshListener>();

    private versionUrl = '';

    private userId: number | null = null;

    /** Never overwritten by later Inertia shared props. */
    private loadedVersion = '';

    private pendingVersion: string | null = null;

    private authorizationRevision: string | null = null;

    private updateAvailable = false;

    private updateDismissedVersion: string | null = null;

    private pwaUpdateWaiting = false;

    private refreshing = false;

    private lastError: string | null = null;

    private syncTimer: number | null = null;

    private inFlight: Promise<void> | null = null;

    private started = false;

    private dialogOpen = false;

    private dialogOpenListeners = new Set<(open: boolean) => void>();

    private removingHttpException: (() => void) | null = null;

    start(options: ControllerOptions): void {
        if (typeof window === 'undefined') {
            return;
        }

        if (this.started && this.userId !== options.userId) {
            this.resetForUserSwitch();
        }

        this.versionUrl = options.versionUrl;

        if (!this.started) {
            this.userId = options.userId;
            this.loadedVersion = options.loadedVersion;
            this.authorizationRevision = options.initialAuthorizationRevision;
            this.updateDismissedVersion = this.readDismissedVersion();
            this.started = true;
            this.bindLifecycle();
            this.schedulePoll();
            void this.check({ reason: 'start', openDialog: true });
        }

        this.emit();
    }

    stop(): void {
        this.clearTimersAndListeners();
        this.started = false;
        this.userId = null;
        this.loadedVersion = '';
        this.pendingVersion = null;
        this.authorizationRevision = null;
        this.updateAvailable = false;
        this.updateDismissedVersion = null;
        this.pwaUpdateWaiting = false;
        this.refreshing = false;
        this.lastError = null;
        this.dialogOpen = false;
        this.inFlight = null;
        this.emit();
        this.setDialogOpen(false);
    }

    subscribe(listener: AppRefreshListener): () => void {
        this.listeners.add(listener);
        listener(this.snapshot());

        return () => {
            this.listeners.delete(listener);
        };
    }

    subscribeDialog(listener: (open: boolean) => void): () => void {
        this.dialogOpenListeners.add(listener);
        listener(this.dialogOpen);

        return () => {
            this.dialogOpenListeners.delete(listener);
        };
    }

    setPwaUpdateWaiting(waiting: boolean): void {
        this.pwaUpdateWaiting = waiting;
        this.recomputeUpdateAvailability(null);

        if (waiting) {
            this.openUpdateDialog({ force: true });
        }

        this.emit();
    }

    /**
     * Sync authorization revision from Inertia. Never replaces loadedVersion.
     * Server version in shared props is treated as a hint only.
     */
    syncFromInertiaProps(
        serverVersion: string,
        authorizationRevision: string | null,
    ): void {
        if (!this.started) {
            return;
        }

        if (authorizationRevision !== null) {
            this.authorizationRevision = authorizationRevision;
        }

        if (serverVersion) {
            this.recomputeUpdateAvailability(serverVersion);
        }

        this.emit();
    }

    async manualRefresh(): Promise<void> {
        if (this.refreshing) {
            return;
        }

        if (!navigator.onLine) {
            toast.warning(
                "You're offline. Connect to the internet to check for updates.",
            );

            return;
        }

        this.refreshing = true;
        this.lastError = null;
        this.emit();

        try {
            const payload = await fetchAppVersion(
                this.loadedVersion,
                this.versionUrl,
            );

            this.recomputeUpdateAvailability(payload.version);
            await this.syncAuthorizationIfNeeded(
                payload.authorization_revision,
            );

            if (this.updateAvailable) {
                // Manual refresh reopens the dialog even after Later.
                this.openUpdateDialog({ force: true });

                return;
            }

            await this.reloadSharedAndPage();
            toast.success('App refreshed.');
        } catch {
            this.lastError = 'Could not refresh the app. Please try again.';
            toast.error(this.lastError);
        } finally {
            this.refreshing = false;
            this.emit();
        }
    }

    dismissUpdate(): void {
        if (this.pendingVersion) {
            this.updateDismissedVersion = this.pendingVersion;
            this.persistDismissedVersion(this.pendingVersion);
        }

        this.setDialogOpen(false);
        this.emit();
    }

    async confirmReload(): Promise<void> {
        if (hasUnsavedWork()) {
            const proceed = window.confirm(
                'You have unsaved changes. Reload anyway and discard them?',
            );

            if (!proceed) {
                return;
            }
        }

        this.setDialogOpen(false);
        await reloadApplication();
    }

    private snapshot(): AppRefreshSnapshot {
        const updateDismissed =
            this.pendingVersion !== null &&
            this.updateDismissedVersion === this.pendingVersion;

        return {
            loadedVersion: this.loadedVersion,
            pendingVersion: this.pendingVersion,
            authorizationRevision: this.authorizationRevision,
            updateAvailable: this.updateAvailable,
            updateDismissed,
            updateDismissedVersion: this.updateDismissedVersion,
            pwaUpdateWaiting: this.pwaUpdateWaiting,
            refreshing: this.refreshing,
            lastError: this.lastError,
        };
    }

    private emit(): void {
        const snapshot = this.snapshot();

        for (const listener of this.listeners) {
            listener(snapshot);
        }
    }

    private setDialogOpen(open: boolean): void {
        this.dialogOpen = open;

        for (const listener of this.dialogOpenListeners) {
            listener(open);
        }
    }

    private openUpdateDialog(options: { force?: boolean } = {}): void {
        if (
            !shouldAutoOpenUpdateDialog({
                updateAvailable: this.updateAvailable,
                pendingVersion: this.pendingVersion,
                dismissedVersion: this.updateDismissedVersion,
                pwaUpdateWaiting: this.pwaUpdateWaiting,
                force: Boolean(options.force),
            })
        ) {
            this.emit();

            return;
        }

        this.setDialogOpen(true);
    }

    private recomputeUpdateAvailability(serverVersion: string | null): void {
        const resolved = resolveUpdateAvailability({
            loadedVersion: this.loadedVersion,
            serverVersion,
            pwaUpdateWaiting: this.pwaUpdateWaiting,
        });

        this.pendingVersion = resolved.pendingVersion;
        this.updateAvailable = resolved.updateAvailable;

        if (
            this.pendingVersion !== null &&
            this.updateDismissedVersion !== null &&
            this.updateDismissedVersion !== this.pendingVersion
        ) {
            this.updateDismissedVersion = null;
            this.persistDismissedVersion(null);
        }
    }

    private schedulePoll(): void {
        this.syncTimer = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            void this.check({ reason: 'poll', openDialog: true });
        }, SYNC_POLL_MS);
    }

    private bindLifecycle(): void {
        window.addEventListener('online', this.handleOnline);
        document.addEventListener(
            'visibilitychange',
            this.handleVisibilityChange,
        );
    }

    private clearTimersAndListeners(): void {
        if (this.syncTimer !== null) {
            window.clearInterval(this.syncTimer);
            this.syncTimer = null;
        }

        window.removeEventListener('online', this.handleOnline);
        document.removeEventListener(
            'visibilitychange',
            this.handleVisibilityChange,
        );
        this.removingHttpException?.();
        this.removingHttpException = null;
    }

    private resetForUserSwitch(): void {
        this.clearTimersAndListeners();
        this.started = false;
        this.pendingVersion = null;
        this.updateAvailable = false;
        this.updateDismissedVersion = null;
        this.pwaUpdateWaiting = false;
        this.dialogOpen = false;
        this.inFlight = null;
    }

    private handleOnline = (): void => {
        void this.check({ reason: 'online', openDialog: true });
    };

    private handleVisibilityChange = (): void => {
        if (!document.hidden) {
            void this.check({ reason: 'visible', openDialog: true });
        }
    };

    private async check(options: {
        reason: string;
        openDialog: boolean;
    }): Promise<void> {
        if (!navigator.onLine || this.refreshing || !this.started) {
            return;
        }

        if (this.inFlight) {
            return this.inFlight;
        }

        this.inFlight = (async () => {
            try {
                const payload = await fetchAppVersion(
                    this.loadedVersion,
                    this.versionUrl,
                );

                this.recomputeUpdateAvailability(payload.version);
                await this.syncAuthorizationIfNeeded(
                    payload.authorization_revision,
                );

                if (options.openDialog && this.updateAvailable) {
                    this.openUpdateDialog({ force: false });
                }

                this.emit();
            } catch {
                // Transient network failures are ignored for background polls.
            } finally {
                this.inFlight = null;
            }
        })();

        return this.inFlight;
    }

    private async syncAuthorizationIfNeeded(
        nextRevision: string | null,
    ): Promise<void> {
        if (
            nextRevision === null ||
            this.authorizationRevision === null ||
            nextRevision === this.authorizationRevision
        ) {
            return;
        }

        await this.reloadAuthorization(nextRevision);
    }

    private reloadAuthorization(nextRevision: string): Promise<void> {
        return new Promise((resolve) => {
            let settled = false;

            const finish = () => {
                if (settled) {
                    return;
                }

                settled = true;
                removeHttpException();
                this.removingHttpException = null;
                this.authorizationRevision = nextRevision;
                this.emit();
                resolve();
            };

            const removeHttpException = router.on('httpException', (event) => {
                const status = httpExceptionStatus(event);

                if (status === 401 || status === 419) {
                    finish();
                    window.location.assign('/login');

                    return;
                }

                if (status === 403) {
                    finish();
                    router.visit('/dashboard', { replace: true });
                }
            });

            this.removingHttpException = removeHttpException;

            // Full reload refreshes shared auth and page-level `can` props.
            // Auth-only then page-only would leave stale page authorization.
            router.reload({
                onSuccess: () => finish(),
                onError: () => finish(),
                onFinish: () => {
                    window.setTimeout(() => {
                        if (!settled) {
                            finish();
                        }
                    }, 0);
                },
            });
        });
    }

    private reloadSharedAndPage(): Promise<void> {
        return new Promise((resolve, reject) => {
            let settled = false;
            let sawAuthFailure = false;

            const removeHttpException = router.on('httpException', (event) => {
                const status = httpExceptionStatus(event);

                if (status === 401 || status === 419) {
                    sawAuthFailure = true;
                    settled = true;
                    removeHttpException();
                    window.location.assign('/login');
                    resolve();

                    return;
                }

                if (status === 403) {
                    sawAuthFailure = true;
                    settled = true;
                    removeHttpException();
                    router.visit('/dashboard', {
                        replace: true,
                        onFinish: () => resolve(),
                    });
                }
            });

            router.reload({
                onSuccess: () => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    removeHttpException();
                    resolve();
                },
                onError: () => {
                    if (settled || sawAuthFailure) {
                        return;
                    }

                    settled = true;
                    removeHttpException();
                    reject(new Error('Refresh failed'));
                },
                onCancel: () => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    removeHttpException();
                    reject(new Error('Refresh cancelled'));
                },
                onFinish: () => {
                    window.setTimeout(() => {
                        if (!settled && !sawAuthFailure) {
                            settled = true;
                            removeHttpException();
                            // Network errors: resolve without dashboard redirect.
                            resolve();
                        }
                    }, 0);
                },
            });
        });
    }

    private readDismissedVersion(): string | null {
        try {
            return sessionStorage.getItem(DISMISS_STORAGE_KEY);
        } catch {
            return null;
        }
    }

    private persistDismissedVersion(version: string | null): void {
        try {
            if (version === null) {
                sessionStorage.removeItem(DISMISS_STORAGE_KEY);
            } else {
                sessionStorage.setItem(DISMISS_STORAGE_KEY, version);
            }
        } catch {
            // sessionStorage may be unavailable.
        }
    }
}

function httpExceptionStatus(event: unknown): number | undefined {
    if (!event || typeof event !== 'object') {
        return undefined;
    }

    const withStatus = event as { status?: number };

    if (typeof withStatus.status === 'number') {
        return withStatus.status;
    }

    const detail = (event as { detail?: { response?: { status?: number } } })
        .detail;

    return detail?.response?.status;
}

export const appRefreshController = new AppRefreshController();
