import { useHttp, usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import DestroyPushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/DestroyPushSubscriptionController';
import StorePushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/StorePushSubscriptionController';
import TestPushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/TestPushSubscriptionController';
import { ensureAppServiceWorker } from '@/lib/register-app-service-worker';

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
 * Use the root-scoped worker served at /sw.js.
 * Do not independently register /service-worker.js.
 */
async function resolvePushServiceWorkerRegistration(): Promise<ServiceWorkerRegistration | null> {
    return ensureAppServiceWorker();
}

function readHttpErrorPayload(error: unknown): {
    status?: number;
    message?: string;
    expired?: boolean;
    errors?: Record<string, string[]>;
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
    let errors: Record<string, string[]> | undefined;

    if (typeof response.data === 'string') {
        try {
            const parsed = JSON.parse(response.data) as {
                message?: string;
                expired?: boolean;
                errors?: Record<string, string[]>;
            };
            message = parsed.message;
            expired = parsed.expired;
            errors = parsed.errors;
        } catch {
            // Ignore non-JSON error bodies.
        }
    } else if (response.data && typeof response.data === 'object') {
        const parsed = response.data as {
            message?: string;
            expired?: boolean;
            errors?: Record<string, string[]>;
        };
        message = parsed.message;
        expired = parsed.expired;
        errors = parsed.errors;
    }

    return {
        status: response.status,
        message,
        expired,
        errors,
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
    const lastSyncedEndpointRef = useRef<string | null>(null);
    const syncPromiseRef = useRef<Promise<void> | null>(null);

    const syncSubscriptionToServer = useCallback(
        async (subscription: PushSubscription) => {
            const json = subscription.toJSON();

            if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
                throw new Error('Push subscription payload is incomplete.');
            }

            const endpoint = json.endpoint;
            const p256dh = json.keys.p256dh;
            const auth = json.keys.auth;
            const contentEncoding = preferredContentEncoding();

            if (
                lastSyncedEndpointRef.current === endpoint &&
                syncPromiseRef.current === null
            ) {
                return;
            }

            if (syncPromiseRef.current) {
                await syncPromiseRef.current;

                if (lastSyncedEndpointRef.current === endpoint) {
                    return;
                }
            }

            const syncPromise = (async () => {
                // useHttp setData is async (React state); post() reads dataRef
                // immediately, so send this payload via transform instead.
                http.transform(() => ({
                    endpoint,
                    keys: {
                        p256dh,
                        auth,
                    },
                    contentEncoding,
                }));

                try {
                    await http.post(StorePushSubscriptionController.url());
                    lastSyncedEndpointRef.current = endpoint;
                } finally {
                    http.transform((data) => data);
                }
            })();

            syncPromiseRef.current = syncPromise;

            try {
                await syncPromise;
            } finally {
                if (syncPromiseRef.current === syncPromise) {
                    syncPromiseRef.current = null;
                }
            }
        },
        [http],
    );

    const refreshStatus = useCallback(async () => {
        if (!browserSupportsWebPush() || !serverConfigured || !userId) {
            const next =
                !browserSupportsWebPush() || !serverConfigured
                    ? 'unsupported'
                    : 'not_enabled';
            setStatus(next);

            return;
        }

        if (Notification.permission === 'denied') {
            setStatus('denied');

            return;
        }

        try {
            const registration = await resolvePushServiceWorkerRegistration();

            if (!registration) {
                setStatus('error');
                setErrorMessage(
                    window.isSecureContext
                        ? 'Could not register the notification service worker. Trust Laravel Herd’s local HTTPS certificate, then reload.'
                        : 'Browser notifications require a trusted HTTPS site (Laravel Herd local certificate).',
                );

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

    const refreshStatusRef = useRef(refreshStatus);
    refreshStatusRef.current = refreshStatus;

    useEffect(() => {
        if (!userId) {
            return;
        }

        let cancelled = false;
        const frame = window.requestAnimationFrame(() => {
            if (!cancelled) {
                void refreshStatusRef.current();
            }
        });

        return () => {
            cancelled = true;
            window.cancelAnimationFrame(frame);
        };
    }, [userId, serverConfigured]);

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
                setStatus('error');
                setErrorMessage(
                    window.isSecureContext
                        ? 'Could not register the notification service worker. Trust Laravel Herd’s local HTTPS certificate, then reload.'
                        : 'Browser notifications require a trusted HTTPS site (Laravel Herd local certificate).',
                );

                return;
            }

            await navigator.serviceWorker.ready;

            // FCM can mark an endpoint expired while Chrome still returns it from
            // getSubscription(). Reusing that zombie always 410s — drop it first.
            const existing = await registration.pushManager.getSubscription();

            if (existing) {
                try {
                    await existing.unsubscribe();
                } catch {
                    // Continue and create a fresh subscription below.
                }
            }

            lastSyncedEndpointRef.current = null;

            const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey.buffer.slice(
                    applicationServerKey.byteOffset,
                    applicationServerKey.byteOffset +
                        applicationServerKey.byteLength,
                ) as ArrayBuffer,
            });

            await syncSubscriptionToServer(subscription);
            lastSyncedEndpointRef.current = subscription.endpoint;
            setStatus('enabled');
        } catch (error: unknown) {
            const payload = readHttpErrorPayload(error);
            setStatus('error');
            setErrorMessage(
                payload.errors?.endpoint?.[0] ??
                    payload.errors?.keys?.[0] ??
                    payload.message ??
                    'Could not enable browser notifications.',
            );
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
                    http.transform(() => ({
                        endpoint: subscription.endpoint,
                    }));

                    try {
                        await http.delete(
                            DestroyPushSubscriptionController.url(),
                        );
                    } finally {
                        http.transform((data) => data);
                    }
                } catch {
                    // Best-effort server detach; still unsubscribe locally.
                }

                await subscription.unsubscribe();
            }

            lastSyncedEndpointRef.current = null;
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

            http.transform(() => ({
                endpoint: subscription.endpoint,
            }));

            try {
                await http.delete(DestroyPushSubscriptionController.url());
            } finally {
                http.transform((data) => data);
            }
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

            http.transform(() => ({
                endpoint: subscription.endpoint,
            }));

            try {
                await http.post(TestPushSubscriptionController.url());
            } finally {
                http.transform((data) => data);
            }

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
                lastSyncedEndpointRef.current = null;

                try {
                    const registration =
                        await resolvePushServiceWorkerRegistration();
                    const stale = registration
                        ? await registration.pushManager.getSubscription()
                        : null;

                    if (stale) {
                        await stale.unsubscribe();
                    }
                } catch {
                    // Best-effort local cleanup before asking the user to re-enable.
                }

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
