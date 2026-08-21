# Crew operational alerts — email delivery

Email for Crew operational alerts is an automatic extension of the in-app / Web Push notification path. There is **no company Email toggle** and no separate recipient-by-email configuration under Crew Settings. Email content and template presentation are managed centrally via the **Email Templates** system under **Settings → Email Templates**.

## Behaviour

- **Alert Reconciliation**: Reconciles operational conditions every 10 minutes (newly detected alert, reactivation of a resolved alert, or meaningful severity escalation).
- **Per-Alert Delivery Ledger**: Creates individual `CrewOperationalAlertEmailDelivery` records for each `(crew_operational_alert_id, user_id, notification_version)` tuple to preserve deduplication and per-alert audit evidence.
- **Configurable Delivery Modes & Timing**:
  - **Scheduled Digest (Default)**: Dispatches a consolidated digest email once daily at the company's configured local time (e.g. `08:00 Asia/Dubai`).
  - **Immediate**: Dispatches digest emails immediately whenever meaningful alerts appear.
  - **Critical Immediate (Default ON)**: When enabled under Scheduled mode, Critical alerts dispatch immediately while Warning and Info alerts wait for the daily scheduled digest.
- **Recipient Grouping & Digesting**: Queued delivery records are grouped by `(company_id, user_id)` and dispatched as **ONE consolidated digest email** per recipient.

Existing Crew notification configuration remains authoritative:

- Crew Notifications master ON/OFF
- Selected active company-member users
- Five alert-type toggles
- Delivery mode: `Scheduled digest` / `Immediate`
- Daily digest time: `HH:MM` (default `08:00` company-local time)
- Critical immediate: boolean (default `true`)

## Delivery Pipeline

```text
Meaningful notification_version event (every 10 minutes)
    ↓
Company Crew Notifications enabled & alert type enabled
    ↓
Selected active recipient with usable email
    ↓
Per-alert delivery ledger row created (Queued)
    ↓
Is Delivery Mode = Immediate OR (Critical & critical_immediate = true)?
├── YES → Grouped by (company_id, user_id) → DeliverCrewOperationalAlertEmailJob dispatched immediately
└── NO (Scheduled mode for Warning/Info) → Delivery row remains Queued in database ledger
    ↓
Company-local scheduled digest time arrives (e.g. 08:00)
    ↓
crew:dispatch-operational-alert-email-digests (scheduled every minute)
    ↓
Grouped by (company_id, user_id) → ONE DeliverCrewOperationalAlertEmailJob per recipient
    ↓
Permission-aware digest rendering via EmailTemplate (crew_operational_alert_digest)
    ↓
ONE Email sent → all queued delivery rows updated to Sent
```

Email is generated for the same events as Web Push:

- newly detected alert
- reactivation of a resolved alert
- meaningful severity escalation

Email is **not** sent for unchanged reconciliation, ordinary `last_detected_at` refresh, or resolution.

## Email Template Integration

- **Slug**: `crew_operational_alert_digest`
- **Category**: `EmailTemplateCategory::Notification` (`notification`)
- **UI Location**: Appears under **Settings → Email Templates → Notifications → Crew Operations alert digest**.
- **Customization & Seeding**: Seeded via `EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate()`. Soft-deleted matching templates are restored without clobbering administrator-customized subject or body HTML.
- **Template Variables**: Supports `{{company_name}}`, `{{alert_count}}`, `{{generated_at}}`, `{{highest_severity}}`, `{{alerts_table}}`, and `{{crew_operations_url}}`.
- **Enabled State**: If `crew_operational_alert_digest.enabled = false`, the email channel suppresses sending without affecting DB alert persistence, in-app alerts, or Web Push notifications.

## Permission-Aware Presentation & Privacy

The digest presenter (`CrewOperationalAlertDigestPresenter`) renders alert rows in a clean HTML table according to the recipient user's permissions within that specific company (`PermissionRegistrar::setPermissionsTeamId($companyId)`):

- **Assignments Permission (`crew_operations.assignments.view`)**: If authorized, includes employee name, employee number, vessel, rank, sign-off date, and remaining/overdue days, with deep-link URL to assignment detail. If unauthorized, displays a privacy-safe generic row ("A Crew Operations item requires review.") without exposing crew or assignment data.
- **Vessel Manning Permission (`crew_operations.vessel_manning.view` / `crew_operations.overview.view`)**: If authorized, includes vessel, rank, and manning shortage details.
- **Deep Links**: Resolved per-user via `ResolveCrewOperationalAlertUrl`. If unauthorized for specific destinations, generic authorized links or no links are presented.

## Deduplication & Audit Ledger

- **Ledger Unique Key**: `(crew_operational_alert_id, user_id, notification_version)`.
- Concurrent reconciliation catches unique constraint violations and avoids double-queueing.
- Every alert/version in a digest batch maintains its own `CrewOperationalAlertEmailDelivery` row for exact audit history.
- Successful send updates all included queued delivery rows to `status = Sent`, `sent_at = now()`.

## SMTP, Retries, and Error Handling

- Uses existing application SMTP via `MailSettingsService` (stored settings take precedence over `.env`).
- Transport failures increment `attempt_count` / `last_attempt_at`, log safe context (company/user/delivery IDs + exception class only), and rethrow for Laravel queue backoff retries `[30, 60, 120]`.
- After retries are exhausted, all applicable queued delivery rows are marked `Failed` with `failure_category = 'email_transport_exhausted'`.
- Failure records and logs must not store SMTP passwords or raw exception messages that may contain credentials.

SMTP transport cannot guarantee exactly-once delivery after an ambiguous network failure; the ledger prevents normal duplicate queueing/reconciliation.

## Tenancy Isolation

- `company_id` is enforced strictly from trusted persisted alert / reconciliation context.
- Digest grouping key is `(company_id, user_id)`. Alerts across different companies for the same user are never combined in a single email.
- Cross-company memberships are rejected for queueing and job execution.

## Relationship with Web Push and In-App Notifications

- Web Push and email delivery queues run independently for the same `notification_version` events.
- In-app notification bell and unread/read state remain unchanged.

## Related Files

- Presenter: `app/Support/CrewOperations/CrewOperationalAlertDigestPresenter.php`
- Queue: `app/Support/CrewOperations/QueueCrewOperationalAlertEmails.php`
- Job: `app/Jobs/DeliverCrewOperationalAlertEmailJob.php`
- Mailable: `app/Mail/CrewOperationalAlertEmailMail.php`
- Blade View: `resources/views/mail/crew-operational-alert-digest.blade.php`
- Seeder: `database/seeders/EmailTemplatesSeeder.php`
- Preview: `app/Support/Email/EmailTemplatePreview.php`
- Reconcile Integration: `app/Support/CrewOperations/ReconcileCrewOperationalAlerts.php`
- SMTP: `app/Services/Settings/MailSettingsService.php`
- Browser Push Companion: [Crew operational alerts Web Push](./crew-operational-alerts-web-push.md)
