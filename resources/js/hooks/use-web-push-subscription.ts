import { useHttp, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import DestroyPushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/DestroyPushSubscriptionController';
import StorePushSubscriptionController from '@/actions/App/Http/Controllers/Notifications/StorePushSubscriptionController';

export type WebPushStatus =
    | 'unsupported'
    | 'not_enabled'
    | 'requesting_permission'
    | 'subscribing'
    | 'enabled'
    | 'denied'
    | 'error';

type WebPushSharedProps = {
    web_push?: {
        vapid_public_key?: string;
        enabled?: boolean;
    };
    auth?: {
        user?: { id: number } | null;
    };
};

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

async function ensureServiceWorkerRegistration(): Promise<ServiceWorkerRegistration> {
    const existing = await navigator.serviceWorker.getRegistration();

    if (existing) {
        return existing;
    }

    return navigator.serviceWorker.register('/service-worker.js');
}

export function useWebPushSubscription() {
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
                contentEncoding: 'aesgcm',
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
            const registration = await ensureServiceWorkerRegistration();
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

            const registration = await ensureServiceWorkerRegistration();
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
            const registration = await ensureServiceWorkerRegistration();
            const subscription =
                await registration.pushManager.getSubscription();

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

    return {
        status,
        errorMessage,
        enable,
        disable,
        detachBeforeLogout,
        refreshStatus,
        serverConfigured,
    };
}
