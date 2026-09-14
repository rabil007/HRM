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

## Built-in email templates

Canonical definitions live in `App\Support\Email\BuiltInEmailTemplates` and are applied by `php artisan db:seed --class=EmailTemplatesSeeder`. Fresh installs and explicit seeding ship production-ready subject/body copy. Administrators should not need to rewrite every template before go-live.

### Seeder safety

Running `EmailTemplatesSeeder` does **not** overwrite administrator customizations.

| Existing row | Seeder behavior |
|--------------|-----------------|
| Missing | Create with the current production default |
| Soft-deleted required built-in | Restore without changing subject, body, TO/CC, enabled, footer, or dispatch settings |
| Present with customized content | Leave subject, body, TO/CC, enabled, footer, dispatch, and default flags untouched |
| Present and still an exact known stock default | Upgrade subject/body (and stock labels) to the current production default |

Known previous stock defaults (for example the original Document share “Overseas Marine Services” copy, or “Automated expiry summary email.”) are listed in the catalog. If subject **and** body both still match a listed stock default, they are upgraded. Any other content is treated as an administrator customization.

### System-managed expiry layouts

`document_expiry_alert` and `company_document_expiry_alert` are HTML summary emails. Subject, table body, and branding come from the Mailables and Blade views, not from the EmailTemplate subject/body fields. Preview uses the production summary layout with **fake sample rows**, never production employee data.

| Template | Runtime-consumed EmailTemplate fields | Recipients | Enable/disable |
|----------|----------------------------------------|------------|----------------|
| `document_expiry_alert` | TO/CC presets, `dispatch_at`, `include_company_footer`, `enabled` | Settings → Email Templates | Template `enabled` |
| `company_document_expiry_alert` | `include_company_footer` only | Company Documents → Expiry Notification Settings | Per-company `enabled` |

The Email Templates UI hides unused controls for these slugs. The unused `email_templates.enabled` field on `company_document_expiry_alert` is not shown as a Disabled badge.

Shared `mail.layout` supplies logo, company name, footer, and contact information. Template bodies should not duplicate that footer.

### Recipients table migration

`company_document_expiry_notification_recipients` is created or repaired in place. Existing recipient rows are never dropped. A later additive migration (`ensure_company_document_expiry_notification_recipients_schema`) is a no-op when the table is already correct.

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
| Category | Notification |
| Job | `SendDocumentExpiryAlertJob` |
| Service | `DocumentExpiryAlertService` |
| Scheduler | `documents:dispatch-expiry-alerts` (daily, via `DocumentExpiryAlertSchedule`) |
| Deduplication | `employee_document_expiry_alerts` ledger (`employee_document_id` + `expiry_date_at_alert_time`) |

Recipients are configured under **Settings → Email Templates → Document expiry alert → TO / CC**.

> **Important:** Employee Document expiry recipients are completely separate from Company Document expiry recipients. Configuring one has no effect on the other.

## Company Document expiry alerts

Daily alerts for expiring company-level documents (Trade License, Establishment Card, etc.). Each company has one notification configuration that applies to every Company Document with an expiry date. There are no per-document recipient overrides and no global recipients.

| Item | Value |
|------|--------|
| Recipients and enabled switch | **Company Documents → Expiry Notification Settings** (`company_document_expiry_notification_settings.enabled` plus TO/CC user lists) |
| Subject and body | `CompanyDocumentExpiryAlertMail` and `mail/company-document-expiry-alert` Blade view |
| Scheduler | Same `documents:dispatch-expiry-alerts` command (dispatches both employee and company alert jobs) |
| Dispatch time | Shared with Employee Document alerts (`DocumentExpiryAlertSchedule` / `document_expiry_alert.dispatch_at`) |
| Job | `SendCompanyDocumentExpiryAlertJob` (unique per company while pending or processing) |
| Service | `CompanyDocumentExpiryAlertService` |
| EmailTemplate slug | `company_document_expiry_alert` — **footer only** (`include_company_footer`) |
| Deduplication | `company_document_expiry_alerts` ledger (`company_document_id` + `expiry_date_at_alert_time`) |

### What the EmailTemplate does not control

The `company_document_expiry_alert` template does **not** control TO/CC recipients, subject, body, dispatch time, or whether emails are sent. Disabling that template does not stop Company Document expiry delivery. The authoritative enable/disable switch is the per-company **Enabled** setting.

### Recipient configuration

Recipients are configured per company at **Organization → Companies → {Company} → Documents → Expiry Notification Settings** (requires `company_documents.manage_notifications`). Each company stores:

- **Enabled / disabled** toggle — source of truth for delivery
- **TO recipients** — OMS-HRM users with active membership in the company
- **CC recipients** — OMS-HRM users with active membership in the company

Selected IDs are validated at save time. Cross-company, inactive, or nonexistent user IDs are rejected with a validation error; they are not silently dropped. Recipient changes write a single company-scoped activity event (`company_document_expiry_notification_recipients_updated`) with before/after TO and CC snapshots. Enabled-state changes are logged on the setting model. Activity is visible only with `audit.view`.

At send time, stored recipients are re-checked: the user must still exist, have a usable email, and still have **active** membership in the same company. Inactive or removed members are skipped. If no valid TO recipient remains, nothing is sent (CC-only is not delivered). Duplicate addresses are compared case-insensitively; TO wins over CC.

The picker lists only active members with usable email addresses. Stored recipients who later become ineligible are omitted from the settings UI and are not emailed.

The same configuration applies to all Company Documents for that company that have an expiry date. There are no per-document recipient overrides.

### Scope

Only Company Documents that satisfy all of the following are eligible:

1. Belong to the active company
2. Have an expiry date
3. Fall within the configured expiry window
4. Company Document expiry notifications are enabled for the company
5. At least one currently eligible TO recipient remains at send time

Soft-deleted documents are excluded. Documents without an expiry date are excluded.

### Deduplication

A `company_document_expiry_alerts` row keyed on `(company_document_id, expiry_date_at_alert_time)` prevents the same expiry event from triggering more than one alert. Historical ledger rows are not deleted. If the document is renewed (expiry date changes), the new expiry date becomes eligible for a fresh alert when it enters the notification window.

Concurrent overlapping jobs for the same company are limited with `ShouldBeUnique` (company ID, 3600s). The unique ledger constraint remains the database backstop.

### Separation from Employee Document alerts

> **Critical:** Company Document expiry recipients are configured per company and are entirely independent of Employee Document expiry recipients. Configuring Employee Document expiry recipients (via Settings → Email Templates) has no effect on Company Document alerts, and vice versa.
