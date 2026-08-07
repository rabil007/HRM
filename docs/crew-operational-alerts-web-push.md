# Crew operational alerts — unified bell and browser Web Push

Browser push for Crew operational alerts is an automatic extension of the in-app Crew notification. There is **no company Browser Push toggle**.

## Behaviour

- Phase 3A persists `CrewOperationalAlert` conditions and company settings (ON/OFF, recipients, alert types).
- Phase 3B adds per-user recipient/read rows (`crew_operational_alert_recipients`) and a push ledger (`crew_operational_alert_push_deliveries`).
- The existing notification bell loads a **unified** server feed (`GET /notifications/feed`) that merges Announcements + Crew operational alerts for the current company and authenticated user.
- Being selected as a Crew notification recipient does **not** grant Crew page permissions.

## Browser Push

Flow:

```text
Crew alert (new / reactivated / severity escalation)
    ↓
company Crew Notifications enabled
    ↓
user is a selected recipient with active membership
    ↓
user has an active Web Push subscription
    ↓
queue DeliverCrewOperationalAlertWebPushJob (afterCommit)
```

Push uses the same Push Subscription / VAPID infrastructure as announcements and document compliance. Users enable notifications from the existing bell control.

## Privacy-safe payload

Lock-screen content is intentionally generic:

- Title: `Crew Operations`
- Body: `Crew Operations requires attention. Open OMS-HRM to review.`

It must not include employee names, vessel names, ranks, assignment numbers, or other operational detail. Detail appears only after opening OMS-HRM with normal authorization.

## Push deduplication

Ledger unique key: `(crew_operational_alert_id, user_id, notification_version)`.

| Event | Behaviour |
|-------|-----------|
| New active alert | `notification_version = 1`, queue push once |
| Unchanged alert | No version bump, no repeated push |
| Severity escalation | Version increments, push again |
| Reactivation after resolve | Version increments, push again |
| Resolved | No resolution push |
| Removed recipient | No future pushes (historical recipient/read rows may remain) |

## Related files

- Feed: `app/Support/Notifications/BuildUnifiedNotificationFeed.php`
- Sync: `app/Support/CrewOperations/SyncCrewOperationalAlertRecipients.php`
- Queue: `app/Support/CrewOperations/QueueCrewOperationalAlertPushes.php`
- Job: `app/Jobs/DeliverCrewOperationalAlertWebPushJob.php`
- Notification: `app/Notifications/CrewOperationalAlertWebPushNotification.php`
- Shared subscriptions / SW: see [Announcement Web Push](./announcements-web-push.md)
