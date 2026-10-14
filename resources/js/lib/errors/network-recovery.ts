import { router } from '@inertiajs/react';
import { hasUnsavedWork } from '@/lib/app-refresh/has-unsaved-work';
import { toast } from '@/lib/toast';

let lastNetworkToastAt = 0;
let offlineToastShown = false;

const TOAST_DEBOUNCE_MS = 4_000;

function canShowNetworkToast(): boolean {
    const now = Date.now();

    if (now - lastNetworkToastAt < TOAST_DEBOUNCE_MS) {
        return false;
    }

    lastNetworkToastAt = now;

    return true;
}

export function resetNetworkRecoveryState(): void {
    lastNetworkToastAt = 0;
    offlineToastShown = false;
}

export function notifyNetworkFailure(): void {
    if (!canShowNetworkToast()) {
        return;
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        if (!offlineToastShown) {
            offlineToastShown = true;
            toast.warning(
                'You appear to be offline. Check your connection and try again.',
            );
        }

        return;
    }

    toast.error(
        'We could not reach the server. Check your connection and try again.',
    );
}

export function notifyOffline(): void {
    if (offlineToastShown || !canShowNetworkToast()) {
        return;
    }

    offlineToastShown = true;
    toast.warning(
        'You appear to be offline. Changes will not save until you reconnect.',
    );
}

export function notifyOnline(): void {
    offlineToastShown = false;

    if (hasUnsavedWork()) {
        toast.info('You are back online. You can retry when ready.');

        return;
    }

    toast.success('You are back online.');
}

export function retryAfterReconnect(): void {
    if (!navigator.onLine) {
        notifyOffline();

        return;
    }

    router.reload();
}
