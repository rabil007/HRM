# Announcement browser Web Push

Browser push notifications are an automatic extension of the existing **In-app** announcement channel. They are not a fourth channel.

## Behaviour

- When an announcement includes `in_app`, OMS-HRM still creates the normal in-app inbox/bell delivery.
- Separately, `DeliverAnnouncementWebPushJob` is queued for each recipient with a linked user account.
- Push is sent to **every active browser subscription** owned by that user (desktop, laptop, PWA, Safari, etc.).
- Email-only or WhatsApp-only announcements never trigger browser push.
- Push is best-effort. Failures never mark in-app deliveries failed and never mark the announcement `partially_delivered`.
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

## Enabling notifications

Users open the notification bell and choose **Enable browser notifications**. OMS-HRM never prompts for permission automatically on page load.

If permission is already granted, the current browser subscription is synchronised silently to the authenticated user.

## Subscription ownership

- Subscriptions belong to the authenticated `User`, not a company.
- One user may have many device subscriptions.
- Endpoints are unique; logging into a shared computer reassigns that endpoint to the current user.
- Explicit logout best-effort detaches the current browser endpoint from the user before Fortify logout completes.

## Privacy

First-version payloads are intentionally generic:

- Title: `OMS-HRM`
- Body: `A new announcement is available. Click to view.`

They must not include confidential HR content on the OS lock screen. Clicking opens `/notifications/announcements/{recipient}/open`, which verifies ownership, activates the recipient company when needed, then redirects to the existing employee announcement page (which marks the recipient/in-app delivery read).

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
- HTTPS is required outside local development.
- Run `php artisan migrate` so the `push_subscriptions` table exists.
- Queue workers must be running (`QUEUE_CONNECTION=database` by default).

## Related code

- Publish trigger: `app/Support/Announcements/Actions/PublishAnnouncement.php`
- Push job: `app/Jobs/DeliverAnnouncementWebPushJob.php`
- Notification: `app/Notifications/AnnouncementWebPushNotification.php`
- Service Worker handlers: `public/service-worker.js` (imported by VitePWA)
- Frontend control: `resources/js/components/web-push-notification-control.tsx`
