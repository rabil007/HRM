import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

/**
 * Activate a waiting service worker (if any) and hard-reload to pick up
 * the newly deployed application assets.
 */
export async function reloadApplication(): Promise<void> {
    try {
        const registration = await ensureAppServiceWorker();
        const waiting = registration?.waiting;

        if (waiting) {
            waiting.postMessage({ type: 'SKIP_WAITING' });
        }
    } catch {
        // Reload still proceeds without SW coordination.
    }

    window.location.reload();
}
