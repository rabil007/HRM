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
