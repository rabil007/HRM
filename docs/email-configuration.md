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

Application branding (logo, name, document title templates, email footer) is managed alongside SMTP in application settings—see recent settings controllers and `app_settings` / cache keys used in `HandleInertiaRequests`.

## Permissions

Check `routes/settings.php` middleware for the exact `settings.*` permission on SMTP routes (typically application settings update permission).

## Operational notes

- Queue: `composer run dev` runs `queue:work --tries=1 --timeout=600` for queued mail and other jobs
- Production: configure real SMTP (Office 365, SendGrid, Amazon SES, etc.) in settings—not `.env` alone once UI settings take precedence

Document bulk email from employee browse uses `DocumentBulkEmailController` and company mail configuration.
