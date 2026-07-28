import { useHttp, usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';
import DestroyPushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/DestroyPushSubscriptionController';
import StorePushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/StorePushSubscriptionController';
import TestPushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/TestPushSubscriptionController';

export type WebPushStatus =
    | 'unsupported'
    | 'not_enabled'
    | 'requesting_permission'
    | 'subscribing'
    | 'enabled'
    | 'denied'
    | 'error';

export type WebPushTestStatus = 'idle' | 'sending' | 'success' | 'error';

type WebPushSharedProps = {
    web_push?: {
        vapid_public_key?: string;
        enabled?: boolean;
    };
    auth?: {
        user?: { id: number } | null;
    };
};

type WebPushContextValue = {
    status: WebPushStatus;
    errorMessage: string | null;
    enable: () => Promise<void>;
    disable: () => Promise<void>;
    detachBeforeLogout: () => Promise<void>;
    refreshStatus: () => Promise<void>;
    sendTest: () => Promise<void>;
    testStatus: WebPushTestStatus;
    testMessage: string | null;
    serverConfigured: boolean;
};

const WebPushContext = createContext<WebPushContextValue | null>(null);

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i += 1) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

function browserSupportsWebPush(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

function preferredContentEncoding(): 'aes128gcm' | 'aesgcm' {
    const encodings = (
        PushManager as unknown as {
            supportedContentEncodings?: readonly string[];
        }
    ).supportedContentEncodings;

    const preferred = encodings?.[0];

    if (preferred === 'aesgcm' || preferred === 'aes128gcm') {
        return preferred;
    }

    return 'aes128gcm';
}

/**
 * Use the VitePWA-generated service worker registration only.
 * Do not independently register /service-worker.js (imported via workbox).
 */
async function resolvePushServiceWorkerRegistration(): Promise<ServiceWorkerRegistration | null> {
    const existing = await navigator.serviceWorker.getRegistration();

    if (existing) {
        await navigator.serviceWorker.ready;

        return existing;
    }

    try {
        const ready = await navigator.serviceWorker.ready;

        return ready;
    } catch {
        return null;
    }
}

function readHttpErrorPayload(error: unknown): {
    status?: number;
    message?: string;
    expired?: boolean;
} {
    if (
        !error ||
        typeof error !== 'object' ||
        !('response' in error) ||
        !error.response ||
        typeof error.response !== 'object'
    ) {
        return {};
    }

    const response = error.response as {
        status?: number;
        data?: unknown;
    };

    let message: string | undefined;
    let expired: boolean | undefined;

    if (typeof response.data === 'string') {
        try {
            const parsed = JSON.parse(response.data) as {
                message?: string;
                expired?: boolean;
            };
            message = parsed.message;
            expired = parsed.expired;
        } catch {
            // Ignore non-JSON error bodies.
        }
    } else if (response.data && typeof response.data === 'object') {
        const parsed = response.data as {
            message?: string;
            expired?: boolean;
        };
        message = parsed.message;
        expired = parsed.expired;
    }

    return {
        status: response.status,
        message,
        expired,
    };
}

function useWebPushSubscriptionState(): WebPushContextValue {
    const page = usePage<WebPushSharedProps>();
    const http = useHttp();
    const vapidPublicKey = page.props.web_push?.vapid_public_key ?? '';
    const serverConfigured = Boolean(
        page.props.web_push?.enabled && vapidPublicKey,
    );
    const userId = page.props.auth?.user?.id ?? null;

    const [status, setStatus] = useState<WebPushStatus>(() => {
        if (!browserSupportsWebPush() || !serverConfigured) {
            return 'unsupported';
        }

        if (Notification.permission === 'denied') {
            return 'denied';
        }

        return 'not_enabled';
    });
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [testStatus, setTestStatus] = useState<WebPushTestStatus>('idle');
    const [testMessage, setTestMessage] = useState<string | null>(null);

    const syncSubscriptionToServer = useCallback(
        async (subscription: PushSubscription) => {
            const json = subscription.toJSON();

            if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
                throw new Error('Push subscription payload is incomplete.');
            }

            http.setData({
                endpoint: json.endpoint,
                keys: {
                    p256dh: json.keys.p256dh,
                    auth: json.keys.auth,
                },
                contentEncoding: preferredContentEncoding(),
            });

            await http.post(StorePushSubscriptionController.url());
        },
        [http],
    );

    const refreshStatus = useCallback(async () => {
        if (!browserSupportsWebPush() || !serverConfigured || !userId) {
            setStatus(
                !browserSupportsWebPush() || !serverConfigured
                    ? 'unsupported'
                    : 'not_enabled',
            );

            return;
        }

        if (Notification.permission === 'denied') {
            setStatus('denied');

            return;
        }

        try {
            const registration = await resolvePushServiceWorkerRegistration();

            if (!registration) {
                setStatus('unsupported');

                return;
            }

            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription && Notification.permission === 'granted') {
                await syncSubscriptionToServer(subscription);
                setStatus('enabled');
                setErrorMessage(null);

                return;
            }

            setStatus('not_enabled');
        } catch {
            setStatus('error');
            setErrorMessage('Unable to sync browser notifications.');
        }
    }, [serverConfigured, syncSubscriptionToServer, userId]);

    useEffect(() => {
        if (!userId) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            void refreshStatus();
        });

        return () => window.cancelAnimationFrame(frame);
    }, [refreshStatus, userId]);

    const enable = useCallback(async () => {
        if (!browserSupportsWebPush() || !serverConfigured || !userId) {
            setStatus('unsupported');

            return;
        }

        setErrorMessage(null);
        setStatus('requesting_permission');

        try {
            const permission =
                Notification.permission === 'granted'
                    ? 'granted'
                    : await Notification.requestPermission();

            if (permission === 'denied') {
                setStatus('denied');

                return;
            }

            if (permission !== 'granted') {
                setStatus('not_enabled');

                return;
            }

            setStatus('subscribing');

            const registration = await resolvePushServiceWorkerRegistration();

            if (!registration) {
                setStatus('unsupported');
                setErrorMessage(
                    'Browser notifications require the installed app service worker.',
                );

                return;
            }

            await navigator.serviceWorker.ready;

            let subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(
                        vapidPublicKey,
                    ) as BufferSource,
                });
            }

            await syncSubscriptionToServer(subscription);
            setStatus('enabled');
        } catch {
            setStatus('error');
            setErrorMessage('Could not enable browser notifications.');
        }
    }, [serverConfigured, syncSubscriptionToServer, userId, vapidPublicKey]);

    const disable = useCallback(async () => {
        if (!browserSupportsWebPush() || !userId) {
            return;
        }

        setErrorMessage(null);

        try {
            const registration = await resolvePushServiceWorkerRegistration();
            const subscription = registration
                ? await registration.pushManager.getSubscription()
                : null;

            if (subscription) {
                try {
                    http.setData({ endpoint: subscription.endpoint });
                    await http.delete(DestroyPushSubscriptionController.url());
                } catch {
                    // Best-effort server detach; still unsubscribe locally.
                }

                await subscription.unsubscribe();
            }

            setStatus(
                Notification.permission === 'denied' ? 'denied' : 'not_enabled',
            );
        } catch {
            setStatus('error');
            setErrorMessage('Could not disable browser notifications.');
        }
    }, [http, userId]);

    const detachBeforeLogout = useCallback(async () => {
        if (!browserSupportsWebPush() || !userId) {
            return;
        }

        try {
            const registration =
                await navigator.serviceWorker.getRegistration();
            const subscription = registration
                ? await registration.pushManager.getSubscription()
                : null;

            if (!subscription) {
                return;
            }

            http.setData({ endpoint: subscription.endpoint });
            await http.delete(DestroyPushSubscriptionController.url());
        } catch {
            // Never block logout.
        }
    }, [http, userId]);

    const sendTest = useCallback(async () => {
        if (testStatus === 'sending') {
            return;
        }

        setTestMessage(null);
        setTestStatus('sending');

        if (!browserSupportsWebPush() || !serverConfigured || !userId) {
            setTestStatus('error');
            setTestMessage('The test notification could not be sent.');

            return;
        }

        if (Notification.permission !== 'granted') {
            setStatus(
                Notification.permission === 'denied' ? 'denied' : 'not_enabled',
            );
            setTestStatus('error');
            setTestMessage(
                'This browser is no longer subscribed. Enable notifications again.',
            );

            return;
        }

        try {
            const registration = await resolvePushServiceWorkerRegistration();

            if (!registration) {
                setTestStatus('error');
                setTestMessage('The test notification could not be sent.');

                return;
            }

            const subscription =
                await registration.pushManager.getSubscription();

            if (!subscription) {
                setStatus('not_enabled');
                setTestStatus('error');
                setTestMessage(
                    'This browser is no longer subscribed. Enable notifications again.',
                );

                return;
            }

            http.setData({
                endpoint: subscription.endpoint,
            });

            await http.post(TestPushSubscriptionController.url());

            setTestStatus('success');
            setTestMessage(
                'Test notification sent. Check your browser notifications.',
            );
        } catch (error: unknown) {
            const payload = readHttpErrorPayload(error);

            if (payload.status === 429) {
                setTestStatus('error');
                setTestMessage(
                    'Too many test requests. Please wait a moment and try again.',
                );

                return;
            }

            if (payload.expired || payload.status === 404) {
                setStatus('not_enabled');
                setTestStatus('error');
                setTestMessage(
                    payload.message ??
                        'This browser is no longer subscribed. Enable notifications again.',
                );

                return;
            }

            setTestStatus('error');
            setTestMessage(
                payload.message ?? 'The test notification could not be sent.',
            );
        }
    }, [http, serverConfigured, testStatus, userId]);

    return useMemo(
        () => ({
            status,
            errorMessage,
            enable,
            disable,
            detachBeforeLogout,
            refreshStatus,
            sendTest,
            testStatus,
            testMessage,
            serverConfigured,
        }),
        [
            status,
            errorMessage,
            enable,
            disable,
            detachBeforeLogout,
            refreshStatus,
            sendTest,
            testStatus,
            testMessage,
            serverConfigured,
        ],
    );
}

type WebPushProviderProps = {
    children: React.ReactNode;
};

export function WebPushProvider({ children }: WebPushProviderProps) {
    const value = useWebPushSubscriptionState();

    return <WebPushContext value={value}>{children}</WebPushContext>;
}

export function useWebPushContext(): WebPushContextValue {
    const context = useContext(WebPushContext);

    if (!context) {
        throw new Error(
            'useWebPushContext must be used within a WebPushProvider.',
        );
    }

    return context;
}
