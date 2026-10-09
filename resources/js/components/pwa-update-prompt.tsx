import { useEffect } from 'react';
import { appRefreshController } from '@/lib/app-refresh/app-refresh-controller';
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

/**
 * Register the root-scoped service worker and route waiting updates through
 * the shared app-refresh update dialog.
 */
export function PwaUpdatePrompt() {
    useEffect(() => {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        let cancelled = false;

        void (async () => {
            try {
                const registration = await ensureAppServiceWorker();

                if (cancelled || !registration) {
                    return;
                }

                if (
                    registration.waiting &&
                    navigator.serviceWorker.controller
                ) {
                    appRefreshController.setPwaUpdateWaiting(true);
                }

                registration.addEventListener('updatefound', () => {
                    const worker = registration.installing;

                    if (!worker) {
                        return;
                    }

                    worker.addEventListener('statechange', () => {
                        if (
                            worker.state === 'installed' &&
                            navigator.serviceWorker.controller
                        ) {
                            appRefreshController.setPwaUpdateWaiting(true);
                        }
                    });
                });
            } catch {
                // Service worker registration is optional for the app shell.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    return null;
}
