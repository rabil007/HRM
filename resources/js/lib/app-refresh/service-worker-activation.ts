export const CONTROLLER_CHANGE_TIMEOUT_MS = 3_000;

export type ServiceWorkerActivationResult =
    | 'activated'
    | 'timeout'
    | 'none'
    | 'error';

type ActivateWaitingOptions = {
    registration: Pick<ServiceWorkerRegistration, 'waiting'> | null;
    timeoutMs?: number;
    postMessage?: (worker: ServiceWorker, message: unknown) => void;
    addControllerChangeListener?: (listener: () => void) => () => void;
};

/**
 * Ask a waiting worker to activate and wait for controllerchange.
 * Timeout does **not** mean activation succeeded — callers may still hard-reload
 * as a fallback to pick up server assets.
 */
export async function activateWaitingServiceWorker(
    options: ActivateWaitingOptions,
): Promise<ServiceWorkerActivationResult> {
    const waiting = options.registration?.waiting ?? null;

    if (!waiting) {
        return 'none';
    }

    const timeoutMs = options.timeoutMs ?? CONTROLLER_CHANGE_TIMEOUT_MS;
    const postMessage =
        options.postMessage ??
        ((worker, message) => {
            worker.postMessage(message);
        });
    const addListener =
        options.addControllerChangeListener ??
        ((listener) => {
            if (
                typeof navigator === 'undefined' ||
                !('serviceWorker' in navigator)
            ) {
                return () => undefined;
            }

            navigator.serviceWorker.addEventListener(
                'controllerchange',
                listener,
            );

            return () => {
                navigator.serviceWorker.removeEventListener(
                    'controllerchange',
                    listener,
                );
            };
        });

    return await new Promise<ServiceWorkerActivationResult>((resolve) => {
        let settled = false;

        const finish = (result: ServiceWorkerActivationResult) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeoutId);
            removeListener();
            resolve(result);
        };

        const onControllerChange = () => {
            finish('activated');
        };

        const removeListener = addListener(onControllerChange);
        const timeoutId = setTimeout(() => {
            finish('timeout');
        }, timeoutMs);

        try {
            postMessage(waiting, { type: 'SKIP_WAITING' });
        } catch {
            finish('error');
        }
    });
}
