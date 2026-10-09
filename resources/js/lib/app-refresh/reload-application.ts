import { markIntentionalUnload } from '@/lib/app-refresh/has-unsaved-work';
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

const CONTROLLER_CHANGE_TIMEOUT_MS = 3_000;

let reloadInFlight = false;

/**
 * Activate a waiting service worker (if any), wait for controllerchange when
 * possible, then hard-reload so the browser picks up deployed assets.
 */
export async function reloadApplication(): Promise<void> {
    if (reloadInFlight) {
        return;
    }

    reloadInFlight = true;
    markIntentionalUnload();

    try {
        const registration = await ensureAppServiceWorker();
        const waiting = registration?.waiting ?? null;

        if (waiting && 'serviceWorker' in navigator) {
            await new Promise<void>((resolve) => {
                let settled = false;

                const finish = () => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    window.clearTimeout(timeoutId);
                    navigator.serviceWorker.removeEventListener(
                        'controllerchange',
                        onControllerChange,
                    );
                    resolve();
                };

                const onControllerChange = () => {
                    finish();
                };

                const timeoutId = window.setTimeout(
                    finish,
                    CONTROLLER_CHANGE_TIMEOUT_MS,
                );

                navigator.serviceWorker.addEventListener(
                    'controllerchange',
                    onControllerChange,
                );
                waiting.postMessage({ type: 'SKIP_WAITING' });
            });
        }
    } catch {
        // Reload still proceeds without SW coordination.
    }

    window.location.reload();
}
