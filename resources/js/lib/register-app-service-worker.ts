/**
 * Ensure the root-scoped OMS-HRM service worker is registered.
 *
 * Laravel serves public/service-worker.js at /sw.js with
 * Service-Worker-Allowed: / so scope "/" is valid for push.
 *
 * On Laravel Herd, Chrome must trust Herd's local CA or register() fails with
 * an SSL certificate error and push stays unavailable.
 */
let registrationPromise: Promise<ServiceWorkerRegistration | null> | null =
    null;

export function ensureAppServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return Promise.resolve(null);
    }

    if (registrationPromise) {
        return registrationPromise;
    }

    registrationPromise = (async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();

        await Promise.all(
            registrations.map(async (registration) => {
                try {
                    const scopePath = new URL(registration.scope).pathname;

                    if (
                        scopePath === '/build/' ||
                        scopePath.startsWith('/build/')
                    ) {
                        await registration.unregister();
                    }
                } catch {
                    // Ignore malformed scopes.
                }
            }),
        );

        try {
            const registration = await navigator.serviceWorker.register(
                '/sw.js',
                {
                    scope: '/',
                },
            );

            // Pick up server-side /sw.js changes without waiting for the
            // browser's own 24h update check — but only when nothing is waiting
            // already, so we do not churn the active worker during subscribe.
            if (registration.waiting === null) {
                await registration.update();
            }

            return registration;
        } catch {
            registrationPromise = null;

            return null;
        }
    })();

    return registrationPromise;
}
