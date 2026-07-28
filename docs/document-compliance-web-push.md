# Document compliance browser Web Push

Browser push for the **Documents & Compliance daily expiry summary** extends the existing email alert. It is not an announcement channel and does not create announcement rows.

## Behaviour

- The existing `document_expiry_alert` email template remains the source of truth for:
  - Whether the alert is enabled
  - Dispatch time (`dispatch_at`)
  - TO recipients (`to_preset`)
  - CC recipients (`cc_preset`)
- When the daily process finds documents in the configured expiry window for a company:
  - The existing consolidated summary email is still sent (email dedupe unchanged)
  - Browser-push users are resolved from the same TO + CC presets
  - One generic Web Push summary is queued **per resolved user**
  - That push reaches every active browser subscription owned by the user
- Email and push are operationally independent: email failure does not block push queueing, and push failure does not roll back email alert records.
- No `Announcement`, `AnnouncementRecipient`, or `AnnouncementDelivery` rows are created.

## Recipient resolution

TO and CC are merged for push (case-insensitive, trimmed, deduplicated).

Each configured email is matched, in the alert company only, against:

1. `users.email`
2. Employee `work_email` with a linked `user_id`
3. Employee `personal_email` with a linked `user_id`

A resolved user must:

- Belong to an **active** company
- Have an **active** company membership
- Be an **active** user (or null status treated as active)
- Have `documents.view` in that company (Spatie team context set to the company during the check)

Email addresses that do not map to an authorised OMS-HRM user still receive email when listed in TO/CC, but receive no push.

Users without push subscriptions are skipped without failing the job.

## Privacy-safe payload

Lock-screen content is intentionally generic:

- Title: `Document compliance alert`
- Body: `Documents require expiry or compliance attention.`
- Tag: `document-compliance-{company_id}`

It must not include employee names, document filenames, expiry dates, counts, email addresses, or other company-sensitive detail.

## Click behaviour

Clicking the notification opens:

```text
GET /notifications/documents/compliance/{company}/open
```

The authenticated, verified user must have active membership and `documents.view` in that company. OMS-HRM activates the company via `ActivateCompanySession` and redirects to Documents & Compliance with the existing `expiry=expiring_30` filter. No public/signed document token is used.

## Deduplication

Email dedupe remains on `employee_document_expiry_alerts`.

Push uses a separate ledger:

```text
document_expiry_push_alerts
```

Unique on `(employee_document_id, user_id, expiry_date_at_alert_time)`.

Statuses: `queued`, `sent`, `failed`.

- The same user is not repeatedly pushed for the same document and unchanged expiry date.
- A changed expiry date may trigger a new alert.
- A newly configured template recipient may receive alerts that were never pushed to that user.
- Provider endpoints, keys, and payloads are never stored on the ledger.

## Queue and retries

`DeliverDocumentComplianceWebPushJob`:

- Implements `ShouldQueue` with retries/backoff consistent with announcement web push
- Is unique per company/user/alert-id set for a short window
- Dispatches with `afterCommit()`
- Re-checks company, membership, permission, documents, and subscriptions at execution time
- Marks ledger rows `sent` after a successful channel send
- Marks final failure with a generic `failure_category` only
- Logs company ID, user ID, attempt, notification type, exception class, and failure category — never endpoints, keys, emails, or raw exception messages

Requires a running queue worker (`php artisan queue:work` or `composer run dev`).

## Requirements

- Trusted HTTPS origin (Herd local CA in development)
- VAPID keys configured (`php artisan webpush:vapid`)
- Users enable browser notifications from the bell control
- Document expiry email template enabled with TO/CC presets

## Related files

- Service: `app/Services/DocumentExpiryAlertService.php`
- Resolver: `app/Support/Notifications/ResolveTemplatePushRecipients.php`
- Job: `app/Jobs/DeliverDocumentComplianceWebPushJob.php`
- Notification: `app/Notifications/DocumentComplianceWebPushNotification.php`
- Open route: `app/Http/Controllers/Notifications/OpenDocumentComplianceNotificationController.php`
- Ledger model: `app/Models/DocumentExpiryPushAlert.php`
- Shared subscriptions / SW: see [Announcement Web Push](./announcements-web-push.md)
