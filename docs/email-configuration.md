# Email configuration

Application email (SMTP) is configured in **Settings → Application** and stored via the app settings system.

## Routes

| Method | Path | Name |
|--------|------|------|
| POST | `/settings/application/smtp` | `application.smtp.update` |
| POST | `/settings/application/smtp/test` | `application.smtp.test` |

Controller: `App\Http\Controllers\Settings\ApplicationSettingsController`

## SMTP update

- Validated by `UpdateApplicationSmtpRequest`
- Persists host, port, encryption, credentials, from address/name into app settings (cached)
- Stored passwords are never returned to the browser. The settings page receives an empty password field plus `has_password` and preserves the stored password when the field is left blank.

## Test email

- Endpoint: `POST /settings/application/smtp/test`
- Request: `TestApplicationMailRequest` — supports customizable **subject**, **body**, and optional **attachment**
- Response: JSON (success/error message for the settings UI)

Use test mail to verify credentials before relying on document email or system notifications.

Announcement **Send test to me** (separate from SMTP settings test) reuses `BuildAnnouncementEmailContent` and the production `mail/announcement` Blade templates, with a `[TEST]` subject prefix. See [Announcements](./announcements.md).

## Branding (related)

Platform branding (logos, platform name, email footer) is managed in **Settings → Application**. Company logos and salary-certificate signature/stamp live on **Organization → Companies**.

Shared Inertia props expose `settings.platform.branding` for platform assets and `settings.company.logo_url` for the active company.

## Permissions

Check `routes/settings.php` middleware for the exact `settings.*` permission on SMTP routes (typically application settings update permission).

## Operational notes

- Queue: `composer run dev` runs `queue:work --tries=1 --timeout=600` for queued mail and other jobs
- Production: configure real SMTP (Office 365, SendGrid, Amazon SES, etc.) in settings—not `.env` alone once UI settings take precedence

Document bulk email from employee browse uses `DocumentBulkEmailController` and company mail configuration.

## Document recipient action requests (Phase 7A)

Recipient signing/acknowledgement requests use the same application SMTP (`MailSettingsService`), the queue worker, and Email Templates.

| Item | Value |
|------|--------|
| Template slug | `document_recipient_action_request` |
| Category | Document |
| Job | `DeliverDocumentRecipientRequestEmailJob` |
| Reconciliation | `documents:dispatch-recipient-emails` (every minute) |

No PDF is attached — the email contains a secure action link only. Delivery evidence lives on `document_recipient_request_deliveries`. See `docs/document-management.md` Phase 7A.

## Document recipient reminders + expiry (Phase 7B)

| Item | Value |
|------|--------|
| Reminder template slug | `document_recipient_action_reminder` |
| Category | Document |
| Job | same `DeliverDocumentRecipientRequestEmailJob` |
| Lifecycle reconciliation | `documents:reconcile-recipient-requests` (every five minutes) |

Both scheduled commands must run in production (Herd scheduler / cron `schedule:run`). Reminder failures never change request or signing-flow state. See `docs/document-management.md` Phase 7B.

## Employee Document expiry alerts

Daily alerts for expiring employee documents. Recipients come from the **global email template**, not from any company-level setting.

| Item | Value |
|------|--------|
| Template slug | `document_expiry_alert` |
| Category | Document |
| Job | `SendDocumentExpiryAlertJob` |
| Service | `DocumentExpiryAlertService` |
| Scheduler | `documents:dispatch-expiry-alerts` (daily, via `DocumentExpiryAlertSchedule`) |
| Deduplication | `employee_document_expiry_alerts` ledger (`employee_document_id` + `expiry_date_at_alert_time`) |

Recipients are configured under **Settings → Email Templates → Document expiry alert → TO / CC**.

> **Important:** Employee Document expiry recipients are completely separate from Company Document expiry recipients. Configuring one has no effect on the other.

## Company Document expiry alerts

Daily alerts for expiring company-level documents (Trade License, Establishment Card, etc.). Each company has its own independent recipient configuration — there are no global recipients for company document alerts.

| Item | Value |
|------|--------|
| Template slug | `company_document_expiry_alert` |
| Category | Document |
| Job | `SendCompanyDocumentExpiryAlertJob` |
| Service | `CompanyDocumentExpiryAlertService` |
| Scheduler | Same `documents:dispatch-expiry-alerts` command (dispatches both employee and company alert jobs) |
| Deduplication | `company_document_expiry_alerts` ledger (`company_document_id` + `expiry_date_at_alert_time`) |

### Recipient configuration

Recipients are configured per company at **Organization → Companies → {Company} → Documents → Expiry Notification Settings** (requires `company_documents.manage_notifications` permission). Each company stores:

- **Enabled / disabled** toggle
- **TO recipients** — OMS-HRM users with active membership in the company
- **CC recipients** — OMS-HRM users with active membership in the company

The same configuration applies to all Company Documents for that company that have an expiry date. There are no per-document recipient overrides.

### Scope

Only Company Documents that satisfy all of the following are eligible:

1. Belong to the active company
2. Have an expiry date
3. Fall within the configured expiry window
4. Company Document expiry notifications are enabled for the company
5. At least one valid TO recipient is configured

Soft-deleted documents are excluded. Documents without an expiry date are excluded.

### Deduplication

A `company_document_expiry_alerts` row keyed on `(company_document_id, expiry_date_at_alert_time)` prevents the same expiry event from triggering more than one alert. If the document is renewed (expiry date changes), the new expiry date becomes eligible for a fresh alert when it enters the notification window.

### Separation from Employee Document alerts

> **Critical:** Company Document expiry recipients are configured per company and are entirely independent of Employee Document expiry recipients. Configuring Employee Document expiry recipients (via Settings → Email Templates) has no effect on Company Document alerts, and vice versa.
