import { router } from '@inertiajs/react';
import { fetchAppVersion } from '@/lib/app-refresh/fetch-app-version';
import { hasUnsavedWork } from '@/lib/app-refresh/has-unsaved-work';
import { reloadApplication } from '@/lib/app-refresh/reload-application';
import type {
    AppRefreshListener,
    AppRefreshSnapshot,
} from '@/lib/app-refresh/types';
import { toast } from '@/lib/toast';

const VERSION_POLL_MS = 90_000;
const AUTH_POLL_MS = 30_000;

const AUTH_ONLY_PROPS = [
    'auth',
    'app_refresh',
    'company_switcher_companies',
    'current_company_id',
    'favorite_destination_keys',
    'settings',
] as const;

type ControllerOptions = {
    versionUrl: string;
    initialVersion: string;
    initialAuthorizationRevision: string | null;
};

class AppRefreshController {
    private listeners = new Set<AppRefreshListener>();

    private versionUrl = '';

    private clientVersion = '';

    private pendingVersion: string | null = null;

    private authorizationRevision: string | null = null;

    private updateAvailable = false;

    private updateDismissedVersion: string | null = null;

    private pwaUpdateWaiting = false;

    private refreshing = false;

    private lastError: string | null = null;

    private versionTimer: number | null = null;

    private authTimer: number | null = null;

    private inFlight: Promise<void> | null = null;

    private started = false;

    private dialogOpen = false;

    private dialogOpenListeners = new Set<(open: boolean) => void>();

    start(options: ControllerOptions): void {
        this.versionUrl = options.versionUrl;
        this.clientVersion = options.initialVersion;
        this.authorizationRevision = options.initialAuthorizationRevision;

        if (this.started || typeof window === 'undefined') {
            this.emit();

            return;
        }

        this.started = true;
        this.bindLifecycle();
        this.schedulePolls();
        void this.check({ reason: 'start' });
        this.emit();
    }

    stop(): void {
        if (this.versionTimer !== null) {
            window.clearInterval(this.versionTimer);
            this.versionTimer = null;
        }

        if (this.authTimer !== null) {
            window.clearInterval(this.authTimer);
            this.authTimer = null;
        }

        window.removeEventListener('online', this.handleOnline);
        document.removeEventListener(
            'visibilitychange',
            this.handleVisibilityChange,
        );
        this.started = false;
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

        if (waiting) {
            this.updateAvailable = true;
            this.openUpdateDialog();
        }

        this.emit();
    }

    syncFromInertiaProps(
        version: string,
        authorizationRevision: string | null,
    ): void {
        if (version) {
            this.clientVersion = version;
        }

        this.authorizationRevision = authorizationRevision;
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
                this.clientVersion,
                this.versionUrl,
            );

            this.applyVersionPayload(payload);

            if (
                payload.authorization_revision !== null &&
                this.authorizationRevision !== null &&
                payload.authorization_revision !== this.authorizationRevision
            ) {
                await this.reloadAuthorization(payload.authorization_revision);
            }

            if (this.updateAvailable || this.pwaUpdateWaiting) {
                this.openUpdateDialog();

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
        const dismissedForPending =
            this.pendingVersion !== null &&
            this.updateDismissedVersion === this.pendingVersion;

        return {
            clientVersion: this.clientVersion,
            authorizationRevision: this.authorizationRevision,
            updateAvailable:
                (this.updateAvailable && !dismissedForPending) ||
                this.pwaUpdateWaiting,
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

    private openUpdateDialog(): void {
        if (
            this.pendingVersion !== null &&
            this.updateDismissedVersion === this.pendingVersion &&
            !this.pwaUpdateWaiting
        ) {
            this.emit();

            return;
        }

        this.setDialogOpen(true);
    }

    private schedulePolls(): void {
        this.versionTimer = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            void this.check({ reason: 'version-poll' });
        }, VERSION_POLL_MS);

        this.authTimer = window.setInterval(() => {
            if (document.hidden) {
                return;
            }

            void this.check({ reason: 'auth-poll' });
        }, AUTH_POLL_MS);
    }

    private bindLifecycle(): void {
        window.addEventListener('online', this.handleOnline);
        document.addEventListener(
            'visibilitychange',
            this.handleVisibilityChange,
        );
    }

    private handleOnline = (): void => {
        void this.check({ reason: 'online' });
    };

    private handleVisibilityChange = (): void => {
        if (!document.hidden) {
            void this.check({ reason: 'visible' });
        }
    };

    private async check(options: { reason: string }): Promise<void> {
        if (!navigator.onLine || this.refreshing) {
            return;
        }

        if (this.inFlight) {
            return this.inFlight;
        }

        this.inFlight = (async () => {
            try {
                const payload = await fetchAppVersion(
                    this.clientVersion,
                    this.versionUrl,
                );

                this.applyVersionPayload(payload);

                if (
                    payload.authorization_revision !== null &&
                    this.authorizationRevision !== null &&
                    payload.authorization_revision !==
                        this.authorizationRevision
                ) {
                    await this.reloadAuthorization(
                        payload.authorization_revision,
                    );
                }

                if (this.updateAvailable && options.reason !== 'auth-poll') {
                    this.openUpdateDialog();
                }
            } catch {
                // Transient network failures are ignored for background polls.
            } finally {
                this.inFlight = null;
            }
        })();

        return this.inFlight;
    }

    private applyVersionPayload(payload: {
        version: string;
        update_available: boolean;
        authorization_revision: string | null;
    }): void {
        const remoteVersion = payload.version;

        if (remoteVersion && remoteVersion !== this.clientVersion) {
            this.pendingVersion = remoteVersion;
            this.updateAvailable = true;

            if (
                this.updateDismissedVersion !== null &&
                this.updateDismissedVersion !== remoteVersion
            ) {
                this.updateDismissedVersion = null;
            }
        } else {
            this.pendingVersion = null;
            this.updateAvailable = Boolean(payload.update_available);
        }

        this.emit();
    }

    private reloadAuthorization(nextRevision: string): Promise<void> {
        return new Promise((resolve) => {
            router.reload({
                only: [...AUTH_ONLY_PROPS],
                onSuccess: () => {
                    this.authorizationRevision = nextRevision;
                    this.emit();
                    resolve();
                },
                onError: () => {
                    router.visit('/dashboard', {
                        replace: true,
                        onFinish: () => {
                            this.authorizationRevision = nextRevision;
                            this.emit();
                            resolve();
                        },
                    });
                },
            });
        });
    }

    private reloadSharedAndPage(): Promise<void> {
        return new Promise((resolve, reject) => {
            router.reload({
                onSuccess: () => resolve(),
                onError: () => {
                    router.visit('/dashboard', {
                        replace: true,
                        onSuccess: () => resolve(),
                        onError: () =>
                            reject(new Error('Refresh failed after redirect')),
                    });
                },
                onCancel: () => reject(new Error('Refresh cancelled')),
            });
        });
    }
}

export const appRefreshController = new AppRefreshController();
