export const WEB_PUSH_ENABLE_PROMPT_DISMISS_MS = 7 * 24 * 60 * 60 * 1000;

type WebPushPromptStatus =
    | 'unsupported'
    | 'not_enabled'
    | 'requesting_permission'
    | 'subscribing'
    | 'enabled'
    | 'denied'
    | 'error';

const dismissalListeners = new Set<() => void>();

export function webPushEnablePromptStorageKey(userId: number): string {
    return `oms-hrm:web-push-prompt-dismissed-until:${userId}`;
}

export function subscribeWebPushPromptDismissal(
    onStoreChange: () => void,
): () => void {
    dismissalListeners.add(onStoreChange);

    return () => {
        dismissalListeners.delete(onStoreChange);
    };
}

export function readWebPushPromptDismissedUntil(userId: number): number | null {
    if (typeof localStorage === 'undefined') {
        return null;
    }

    const raw = localStorage.getItem(webPushEnablePromptStorageKey(userId));

    if (!raw) {
        return null;
    }

    const parsed = Number.parseInt(raw, 10);

    if (!Number.isFinite(parsed) || parsed <= 0) {
        return null;
    }

    return parsed;
}

export function isWebPushEnablePromptDismissed(
    dismissedUntil: number | null,
    now: number,
): boolean {
    return dismissedUntil !== null && dismissedUntil > now;
}

export function dismissWebPushEnablePrompt(
    userId: number,
    now: number = Date.now(),
): number {
    const until = now + WEB_PUSH_ENABLE_PROMPT_DISMISS_MS;

    localStorage.setItem(webPushEnablePromptStorageKey(userId), String(until));
    dismissalListeners.forEach((listener) => listener());

    return until;
}

export function shouldShowWebPushEnablePrompt({
    userId,
    serverConfigured,
    browserSupportsWebPush,
    status,
    notificationPermission,
    isDismissed,
}: {
    userId: number | null;
    serverConfigured: boolean;
    browserSupportsWebPush: boolean;
    status: WebPushPromptStatus;
    notificationPermission: string;
    isDismissed: boolean;
}): boolean {
    if (userId === null) {
        return false;
    }

    if (!serverConfigured || !browserSupportsWebPush) {
        return false;
    }

    if (status !== 'not_enabled') {
        return false;
    }

    if (notificationPermission !== 'default') {
        return false;
    }

    if (isDismissed) {
        return false;
    }

    return true;
}
