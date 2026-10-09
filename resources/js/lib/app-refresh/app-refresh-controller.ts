import { router } from '@inertiajs/react';
import { fetchAppVersion } from '@/lib/app-refresh/fetch-app-version';
import { hasUnsavedWork } from '@/lib/app-refresh/has-unsaved-work';
import {
    resolveInertiaVisitAction,
    shouldAdvanceAuthorizationRevision,
    shouldReportManualRefreshSuccess,
} from '@/lib/app-refresh/inertia-visit-outcome';
import type { InertiaVisitEvent } from '@/lib/app-refresh/inertia-visit-outcome';
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
     * Apply version/auth hints from a successful Inertia page payload.
     * Never replaces loadedVersion. Does not invent success for failed visits —
     * callers only invoke this when page props actually updated.
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

        if (this.inFlight) {
            try {
                await this.inFlight;
            } catch {
                // Prior poll failure should not block a manual refresh.
            }
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
                this.openUpdateDialog({ force: true });

                return;
            }

            await this.reloadSharedAndPage();
            toast.success('App refreshed.');
        } catch (error) {
            if (
                error instanceof Error &&
                error.message === 'Refresh cancelled'
            ) {
                this.lastError = null;
            } else {
                this.lastError = 'Could not refresh the app. Please try again.';
                toast.error(this.lastError);
            }
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

    private reloadAuthorization(expectedRevision: string): Promise<void> {
        return new Promise((resolve) => {
            let settled = false;
            let revisionFromSuccess: string | null = null;

            const cleanup = () => {
                removeHttpException();
                this.removingHttpException = null;
            };

            const settle = (event: InertiaVisitEvent) => {
                const action = resolveInertiaVisitAction(event, settled);

                if (action === 'ignore') {
                    return;
                }

                settled = true;
                cleanup();

                if (shouldAdvanceAuthorizationRevision(action)) {
                    this.authorizationRevision =
                        revisionFromSuccess ?? expectedRevision;
                    this.emit();
                }

                if (action === 'login') {
                    window.location.assign('/login');
                    resolve();

                    return;
                }

                if (action === 'forbidden') {
                    let redirected = false;
                    router.visit('/dashboard', {
                        replace: true,
                        onSuccess: () => {
                            redirected = true;
                            resolve();
                        },
                        onError: () => {
                            redirected = true;
                            resolve();
                        },
                        onCancel: () => {
                            redirected = true;
                            resolve();
                        },
                        onFinish: () => {
                            window.setTimeout(() => {
                                if (!redirected) {
                                    resolve();
                                }
                            }, 0);
                        },
                    });

                    return;
                }

                // succeed or fail — keep old revision on fail so the next poll retries.
                resolve();
            };

            const removeHttpException = router.on('httpException', (event) => {
                const status = httpExceptionStatus(event);

                if (status === 401) {
                    settle('http_401');

                    return;
                }

                if (status === 419) {
                    settle('http_419');

                    return;
                }

                if (status === 403) {
                    settle('http_403');
                }
            });

            this.removingHttpException = removeHttpException;

            router.reload({
                onSuccess: (page) => {
                    const pageRevision = (
                        page?.props as {
                            app_refresh?: {
                                authorization_revision?: string | null;
                            };
                        }
                    )?.app_refresh?.authorization_revision;

                    if (
                        typeof pageRevision === 'string' &&
                        pageRevision !== ''
                    ) {
                        revisionFromSuccess = pageRevision;
                    }

                    settle('success');
                },
                onError: () => settle('error'),
                onCancel: () => settle('cancel'),
                onFinish: () => {
                    window.setTimeout(() => {
                        // Unsettled finish is failure — do not advance revision.
                        settle('finish');
                    }, 0);
                },
            });
        });
    }

    private reloadSharedAndPage(): Promise<void> {
        return new Promise((resolve, reject) => {
            let settled = false;

            const cleanup = () => {
                removeHttpException();
            };

            const settle = (event: InertiaVisitEvent) => {
                const action = resolveInertiaVisitAction(event, settled);

                if (action === 'ignore') {
                    return;
                }

                settled = true;
                cleanup();

                if (action === 'login') {
                    window.location.assign('/login');
                    reject(new Error('Session expired'));

                    return;
                }

                if (action === 'forbidden') {
                    let redirected = false;
                    router.visit('/dashboard', {
                        replace: true,
                        onSuccess: () => {
                            redirected = true;
                            resolve();
                        },
                        onError: () => {
                            redirected = true;
                            reject(new Error('Refresh failed after redirect'));
                        },
                        onCancel: () => {
                            redirected = true;
                            reject(new Error('Refresh cancelled'));
                        },
                        onFinish: () => {
                            window.setTimeout(() => {
                                if (!redirected) {
                                    reject(
                                        new Error(
                                            'Refresh failed after redirect',
                                        ),
                                    );
                                }
                            }, 0);
                        },
                    });

                    return;
                }

                if (shouldReportManualRefreshSuccess(action)) {
                    resolve();

                    return;
                }

                if (event === 'cancel') {
                    reject(new Error('Refresh cancelled'));

                    return;
                }

                reject(new Error('Refresh failed'));
            };

            const removeHttpException = router.on('httpException', (event) => {
                const status = httpExceptionStatus(event);

                if (status === 401) {
                    settle('http_401');

                    return;
                }

                if (status === 419) {
                    settle('http_419');

                    return;
                }

                if (status === 403) {
                    settle('http_403');
                }
            });

            router.reload({
                onSuccess: () => settle('success'),
                onError: () => settle('error'),
                onCancel: () => settle('cancel'),
                onFinish: () => {
                    window.setTimeout(() => {
                        // Unsettled finish is failure — never report success.
                        settle('finish');
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
