import { markIntentionalUnload } from '@/lib/app-refresh/has-unsaved-work';
import { activateWaitingServiceWorker } from '@/lib/app-refresh/service-worker-activation';
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

export {
    activateWaitingServiceWorker,
    CONTROLLER_CHANGE_TIMEOUT_MS,
} from '@/lib/app-refresh/service-worker-activation';
export type { ServiceWorkerActivationResult } from '@/lib/app-refresh/service-worker-activation';

let reloadInFlight = false;

/**
 * Activate a waiting service worker when possible, then hard-reload.
 * A controllerchange timeout still reloads as a best-effort asset refresh;
 * it does not claim the new worker activated.
 */
export async function reloadApplication(): Promise<void> {
    if (reloadInFlight) {
        return;
    }

    reloadInFlight = true;
    markIntentionalUnload();

    try {
        if ('serviceWorker' in navigator) {
            const registration = await ensureAppServiceWorker();
            await activateWaitingServiceWorker({ registration });
        }
    } catch {
        // Reload still proceeds without SW coordination.
    }

    window.location.reload();
}

/** Test helper */
export function resetReloadApplicationGuard(): void {
    reloadInFlight = false;
}
