# Hikvision integration

Hikvision is a **company-owned** integration. Settings, devices, person groups, persons, and access events belong to one OMS company.

## Configuration location

Configure credentials and schedules under:

**Company Settings → Integrations → Hikvision** (`/settings/integrations/hikvision`)

Select the company first. Switching companies loads that company’s Hikvision configuration only. Hikvision fields live in `hikvision_settings`, not on the `companies` table. Each company has at most one settings row (`unique(company_id)`).

Opening the settings page is read-only: it does not create or restore settings rows. Rows are created or restored only when an authorized user saves.

## Credentials and secrets

API key, API secret, and webhook verify token are encrypted at rest. A company is configured only when it has its own stored host, key, and secret and the integration is enabled. Global `.env` / `config/hikvision.php` credentials are never used at runtime for company integrations.

Settings pages return masked empty fields with `has_api_key`, `has_api_secret`, and `has_webhook_verify_token`. Blank credential submissions preserve stored values. Decrypted secrets are never logged.

## Webhooks

Each settings row has a non-sequential `public_id`. The callback URL is:

`/integrations/hikvision/webhook/{publicIntegrationId}`

Processing resolves only integrations with a non-null `company_id` and `webhook_enabled = true`. Signature failures, disabled integrations, and orphan (`company_id` null) rows all return a generic 404. Payload `company_id` values are ignored.

## Scheduled jobs

Morning and evening fetch commands run every minute. They dispatch only for configured company-owned settings whose enabled schedule time matches the current application timezone. Each `FetchHikvisionAccessEventsJob` carries `hikvision_setting_id`. Jobs with missing settings or `company_id = null` exit safely without syncing attendance for company `0`.

## Tenant isolation

All list, sync, link, filter, and export operations scope by the active `current_company_id`. Cross-company employee–person links and person mutations are rejected. Historical access-event `company_id` does not change when an employee is relinked or moved. New persons, devices, groups, and events always require a positive `company_id`.

## Legacy backfill

The ownership migration may leave unresolved historical rows with `company_id = null`. Those rows stay excluded from company views, webhooks, and jobs, and are never assigned by matching names. Reconcile them manually before treating isolation as complete for historical data.
