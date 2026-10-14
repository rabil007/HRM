import { toast } from '@/lib/toast';

let lastNetworkToastAt = 0;
let offlineNotified = false;
let serverUnreachableNotified = false;
let wasOffline = false;

const TOAST_DEBOUNCE_MS = 4_000;

function canShowNetworkToast(): boolean {
    const now = Date.now();

    if (now - lastNetworkToastAt < TOAST_DEBOUNCE_MS) {
        return false;
    }

    lastNetworkToastAt = now;

    return true;
}

export function resetNetworkRecoveryStateForTests(): void {
    lastNetworkToastAt = 0;
    offlineNotified = false;
    serverUnreachableNotified = false;
    wasOffline = false;
}

export function getNetworkRecoveryStateForTests(): {
    wasOffline: boolean;
    offlineNotified: boolean;
    serverUnreachableNotified: boolean;
} {
    return {
        wasOffline,
        offlineNotified,
        serverUnreachableNotified,
    };
}

export function notifyNetworkFailure(): void {
    if (!canShowNetworkToast()) {
        return;
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        notifyOffline();

        return;
    }

    if (serverUnreachableNotified) {
        return;
    }

    serverUnreachableNotified = true;
    toast.error(
        'We could not reach the server. Check your connection and try again.',
    );
}

export function notifyOffline(): void {
    wasOffline = true;

    if (offlineNotified || !canShowNetworkToast()) {
        return;
    }

    offlineNotified = true;
    toast.warning(
        'You appear to be offline. Changes will not save until you reconnect.',
    );
}

export function notifyOnline(): void {
    if (!wasOffline && !offlineNotified && !serverUnreachableNotified) {
        return;
    }

    const hadOffline = wasOffline || offlineNotified;

    offlineNotified = false;
    serverUnreachableNotified = false;
    wasOffline = false;

    if (!hadOffline) {
        return;
    }

    if (!canShowNetworkToast()) {
        return;
    }

    toast.success('You are back online.');
}
