# Document compliance browser Web Push

Browser push for the **Documents & Compliance daily expiry summary** extends the existing email alert. It is not an announcement channel and does not create announcement rows.

## Behaviour

- The `document_expiry_alert` email template remains the source of truth for:
  - Whether the alert channel is enabled
  - Dispatch time (`dispatch_at`)
  - Company footer
- **Recipients** come from company-scoped **Notification Routing** rules (Documents → Configuration → Notification Routing), not from template TO/CC presets.
- When the daily process finds documents in the configured expiry window for a company:
  - Each enabled routing rule may send a consolidated email for its matching document types
  - Browser-push users are resolved from **OMS-HRM user** recipients on those rules (TO and CC)
  - Manual/external email recipients receive email only (no Web Push)
  - Document-type routing and `EmployeeVisibilityScope` are applied before queueing push for a user
  - One generic Web Push summary is queued **per resolved user**
  - That push reaches every active browser subscription owned by the user
- Email and push are operationally independent: email failure does not block push queueing, and push failure does not roll back email alert records.
- No `Announcement`, `AnnouncementRecipient`, or `AnnouncementDelivery` rows are created.

## Recipient resolution

For each enabled routing rule that covers a document type in the expiry window:

1. Resolve active company members selected as user recipients (inactive membership / unusable email → skipped)
2. Filter documents by the user’s employee visibility scope
3. Require `documents.view` is **not** re-checked here for push eligibility beyond active membership used at configuration time; delivery jobs still re-validate company membership and subscriptions at send time as before

Manual email recipients never receive Web Push.

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

Email dedupe is routing-aware on `employee_document_expiry_alerts`:

```text
(notification_rule_id, employee_document_id, expiry_date_at_alert_time)
```

Push uses a separate ledger:

```text
document_expiry_push_alerts
```

Unique on `(employee_document_id, user_id, expiry_date_at_alert_time)`.

Statuses: `queued`, `sent`, `failed`.

- The same user is not repeatedly pushed for the same document and unchanged expiry date.
- A changed expiry date may trigger a new alert.
- A newly configured routing rule user may receive alerts that were never pushed to that user.
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
- Users enable browser notifications from the bell control, or from the in-app **Stay updated** reminder (native permission is still requested only after they click Enable). See [Announcement Web Push](./announcements-web-push.md).
- Document expiry email template enabled
- At least one Notification Routing rule with OMS-HRM user recipients for push; manual-only rules send email without push
