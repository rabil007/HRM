/* OMS-HRM push notification handlers.
 * Imported into the VitePWA-generated service worker via workbox.importScripts.
 * Also usable as a standalone push-only service worker when no PWA worker is registered.
 */

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = {
            title: 'OMS-HRM',
            body: event.data ? event.data.text() : 'A new announcement is available. Click to view.',
        };
    }

    const title = typeof payload.title === 'string' && payload.title !== ''
        ? payload.title
        : 'OMS-HRM';
    const body = typeof payload.body === 'string' && payload.body !== ''
        ? payload.body
        : 'A new announcement is available. Click to view.';
    const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
    const tag = typeof payload.tag === 'string' && payload.tag !== ''
        ? payload.tag
        : 'oms-hrm-announcement';

    event.waitUntil(
        self.registration.showNotification(title, {
            body,
            icon: payload.icon || '/icons/icon-192x192.png',
            badge: payload.badge || '/icons/icon-96x96.png',
            tag,
            data,
            renotify: true,
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data && typeof event.notification.data === 'object'
        ? event.notification.data
        : {};
    const targetUrl = typeof data.url === 'string' ? data.url : '/dashboard';

    event.waitUntil(
        (async () => {
            let url;

            try {
                url = new URL(targetUrl, self.location.origin);
            } catch (error) {
                return;
            }

            if (url.origin !== self.location.origin) {
                return;
            }

            const clientsList = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of clientsList) {
                if ('focus' in client) {
                    await client.focus();

                    if ('navigate' in client) {
                        await client.navigate(url.href);
                    }

                    return;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(url.href);
            }
        })(),
    );
});
