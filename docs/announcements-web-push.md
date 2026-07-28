# Announcement browser Web Push

Browser push notifications are an automatic extension of the existing **In-app** announcement channel. They are not a fourth channel.

## Behaviour

- When an announcement includes `in_app`, OMS-HRM still creates the normal in-app inbox/bell delivery.
- Separately, `DeliverAnnouncementWebPushJob` is queued for each recipient with a linked user account.
- Push is sent to **every active browser subscription** owned by that user (desktop, laptop, PWA, Safari, etc.).
- Email-only or WhatsApp-only announcements never trigger browser push.
- Push is best-effort and independent from `announcement_deliveries`.
- Transient transport failures are retried by the queue; exhausted failures never mark in-app deliveries failed and never mark the announcement `partially_delivered`.
- The notification bell and 60-second feed polling remain the authoritative inbox.

## Channels remain unchanged

Visible announcement channels stay:

- In-app
- Email
- WhatsApp

There is no Browser Push checkbox and no `web_push` rows in `announcement_deliveries`.

## Desktop and PWA

- Desktop Chrome/Edge/Firefox can receive push without installing the PWA when the browser supports Service Worker, Notifications, Push API, and VAPID.
- PWA installation is optional.
- Permission must be granted separately on each browser/device.
- HTTPS is required outside local development.

## Enabling notifications

Users open the notification bell and choose **Enable browser notifications**. OMS-HRM never prompts for permission automatically on page load.

If permission is already granted, the current browser subscription is synchronised silently to the authenticated user.

A single `WebPushProvider` in the authenticated layout owns that synchronisation. The bell control and both sign-out dialogs consume the shared context so only one sync POST runs on page load.

When browser notifications are enabled for the current device, the bell control also shows **Send test notification**. That action:

- Targets **only the current browser/device subscription** (endpoint from `pushManager.getSubscription()`)
- Sends a focused test push **synchronously** (`SendTestWebPushJob::dispatchSync`) so a popup can appear without a queue worker
- Never creates an announcement, recipient, delivery row, or inbox bell item
- Never sends email or WhatsApp
- Never notifies the user’s other devices
- Is rate-limited (`throttle:5,1`)

The UI reports that the test was sent after the push provider accepts it. Announcement browser push still uses the async queue (`DeliverAnnouncementWebPushJob`) and needs `php artisan queue:work` (or `composer run dev`).

## Subscription ownership and limits

- Subscriptions belong to the authenticated `User`, not a company.
- One user may have up to **10** browser subscriptions.
- Updating an existing owned endpoint is always allowed at the limit.
- Logging into a shared computer reassigns that endpoint to the current user.
- Explicit logout best-effort detaches the current browser endpoint from the user before Fortify logout completes.
- Client-supplied ownership fields (`user_id`, `company_id`, morph keys) are rejected.

## Endpoint security

Push endpoints are validated before storage:

- HTTPS only
- No credentials or URL fragments
- No localhost / `.localhost` hosts
- No IP-literal hosts
- Hostnames must resolve to public addresses (loopback/private/link-local/reserved/multicast rejected)
- Endpoints are never logged

Subscription routes are rate-limited (`throttle:20,1`).

## Content encoding

The browser-supported encoding is preferred (`PushManager.supportedContentEncodings[0]`), falling back to **`aes128gcm`**. Legacy `aesgcm` remains accepted for older subscriptions.

## Company activation

Opening a push notification activates the recipient company only when:

- The company exists and is **active**
- The user has an **active** `company_user` membership (or the legacy home-company convention when no pivot row exists)

Inactive memberships and inactive companies are rejected.

## Service Worker architecture

`public/service-worker.js` holds the push handlers. OMS-HRM serves it from:

```text
GET /sw.js
```

with the `Service-Worker-Allowed: /` header so the worker can control the whole origin (not only `/build/`).

The frontend registers `/sw.js` with `scope: '/'`, calls `registration.update()` so server-side worker changes are picked up immediately, and unregisters legacy `/build/`-scoped workers.

The route deliberately serves the lightweight push worker instead of the VitePWA/Workbox artifact at `public/build/sw.js`. That build worker precaches hashed production assets which 404 under `npm run dev`; the worker then stays unhealthy, so FCM accepts the push (201) while the browser never shows a notification.

### Laravel Herd note

Browser push requires a **trusted** HTTPS origin. If Chrome shows “Not Secure” for `https://oms-hrm.test`, Service Worker registration fails (console: SSL certificate error) and OMS-HRM cannot enable push.

Trust Herd’s local CA (Herd app → Settings / General → trust certificate), or run:

```bash
herd secure oms-hrm
```

Then fully reload the site and try **Enable browser notifications** again.

## Queue behaviour

Announcement channel jobs (in-app, email, WhatsApp, and web push) are dispatched with `afterCommit()` so they do not run if the publish transaction rolls back.

Web push failure logging includes recipient ID, user ID, attempt, exception class, and a generic failure category — never endpoints, keys, auth tokens, or raw exception messages.

## Privacy

First-version payloads are intentionally generic:

- Title: `OMS-HRM`
- Body: `A new announcement is available. Click to view.`

They must not include confidential HR content on the OS lock screen. Clicking opens `/notifications/announcements/{recipient}/open`, which verifies ownership, activates the recipient company when authorised, then redirects to the existing employee announcement page (which marks the recipient/in-app delivery read).

## Production configuration

Generate and store stable VAPID keys:

```bash
php artisan webpush:vapid
```

Required environment variables:

```env
VAPID_SUBJECT="${APP_URL}"
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Notes:

- Never commit a real private key.
- Keep production VAPID keys stable after deployment.
- `VAPID_SUBJECT` must be a valid URL or `mailto:` address (required for Safari).
- Run `php artisan migrate` so the `push_subscriptions` table exists.
- Queue workers must be running (`QUEUE_CONNECTION=database` by default).

## Related code

- Publish trigger: `app/Support/Announcements/Actions/PublishAnnouncement.php`
- Push job: `app/Jobs/DeliverAnnouncementWebPushJob.php`
- Notification: `app/Notifications/AnnouncementWebPushNotification.php`
- Test push controller: `app/Http/Controllers/Notifications/TestPushSubscriptionController.php`
- Test push job: `app/Jobs/SendTestWebPushJob.php`
- Test notification: `app/Notifications/TestWebPushNotification.php`
- Endpoint rule: `app/Rules/ValidWebPushEndpoint.php`
- Service Worker handlers: `public/service-worker.js` (served at `/sw.js`)
- Service Worker route: `app/Http/Controllers/ServiceWorkerController.php`
- Frontend provider: `resources/js/context/web-push-provider.tsx`
- Frontend control: `resources/js/components/web-push-notification-control.tsx`
