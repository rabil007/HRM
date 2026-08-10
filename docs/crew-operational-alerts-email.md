# Crew operational alerts — email delivery

Email for Crew operational alerts is an automatic extension of the in-app / Web Push notification path. There is **no company Email toggle** and no separate recipient-by-email configuration under Crew Settings.

## Behaviour

- Phase 3A persists alerts and company notification settings (ON/OFF, selected users, alert types).
- Phase 3B adds recipient/read rows, unified bell, and browser push.
- Phase 3C adds an email delivery ledger (`crew_operational_alert_email_deliveries`) and queued send jobs.

Existing Crew notification configuration remains authoritative:

- Crew Notifications master ON/OFF
- selected active company-member users
- five alert-type toggles

## When email is attempted

```text
Meaningful notification_version event
    ↓
company Crew Notifications enabled
    ↓
alert type enabled
    ↓
user is a selected recipient with active membership
    ↓
user has a usable email address
    ↓
application SMTP is configured (MailSettingsService)
    ↓
queue DeliverCrewOperationalAlertEmailJob (afterCommit)
```

Email is generated for the same events as Web Push:

- newly detected alert
- reactivation of a resolved alert
- meaningful severity escalation

Email is **not** sent for unchanged reconciliation, ordinary `last_detected_at` refresh, or resolution.

## Deduplication

Ledger unique key: `(crew_operational_alert_id, user_id, notification_version)`.

Concurrent reconciliation catches unique violations and does not double-queue.

## Privacy

Subject: `Crew Operations requires attention`

The subject and body must not include employee names, vessel names, ranks, assignment numbers, or document identifiers. A generic severity indicator may appear. Detail is only available after opening OMS-HRM with normal authorization.

CTA label: `Open OMS-HRM` — destination from `ResolveCrewOperationalAlertUrl`. If that returns `null`, the CTA is omitted (no unauthorized deep link).

## SMTP and retries

- Uses existing application SMTP via `MailSettingsService` (stored settings take precedence over `.env`).
- Does not introduce another SMTP configuration UI.
- Transport failures increment `attempt_count` / `last_attempt_at`, log safe context (company/user/delivery ids + exception class only), and rethrow for Laravel retry.
- After retries are exhausted, status becomes Failed with `email_transport_exhausted`.
- Failure records and logs must not store SMTP passwords or raw exception messages that may contain credentials.

SMTP transport cannot guarantee exactly-once delivery after an ambiguous network failure; the ledger prevents normal duplicate queueing/reconciliation.

## Tenancy and permissions

- `company_id` comes from trusted persisted alert / reconciliation context.
- Being selected as a recipient does not grant Crew page permissions.
- Cross-company memberships are rejected for queueing and job execution.

## Related files

- Queue: `app/Support/CrewOperations/QueueCrewOperationalAlertEmails.php`
- Job: `app/Jobs/DeliverCrewOperationalAlertEmailJob.php`
- Mailable: `app/Mail/CrewOperationalAlertEmailMail.php`
- View: `resources/views/mail/crew-operational-alert.blade.php`
- Reconcile integration: `app/Support/CrewOperations/ReconcileCrewOperationalAlerts.php`
- SMTP: `app/Services/Settings/MailSettingsService.php`
- Browser Push companion: [Crew operational alerts Web Push](./crew-operational-alerts-web-push.md)
