# Hikvision integration

Hikvision is a **company-owned** integration. Settings, devices, person groups, persons, and access events belong to one OMS company.

## Configuration location

Configure credentials and schedules under:

**Company Settings → Application → Hikvision** (Integrations tab)

Select the company first. Switching companies loads that company’s Hikvision configuration only. Hikvision fields live in `hikvision_settings`, not on the `companies` table. Each company has at most one settings row (`unique(company_id)`).

## Credentials and secrets

API key, API secret, and webhook verify token are encrypted at rest. Settings pages return masked empty fields with `has_api_key`, `has_api_secret`, and `has_webhook_verify_token`. Blank credential submissions preserve stored values. Decrypted secrets are never logged.

## Webhooks

Each settings row has a non-sequential `public_id`. The callback URL is:

`/integrations/hikvision/webhook/{publicIntegrationId}`

Processing resolves the settings row from `public_id`, verifies the signature with that row’s secret, then stores events under that settings row’s `company_id`. Payload `company_id` values are ignored. Unknown public IDs return a generic 404.

## Scheduled jobs

Morning and evening fetch commands run every minute. They dispatch only for configured company settings whose enabled schedule time matches the current application timezone. Each `FetchHikvisionAccessEventsJob` carries `hikvision_setting_id`, uses that company’s credentials, writes company-scoped records, and updates only that company’s fetch status.

## Tenant isolation

All list, sync, link, filter, and export operations scope by the active `current_company_id`. Cross-company employee–person links and person mutations are rejected. Historical access-event `company_id` does not change when an employee is relinked or moved.

## Legacy backfill

The ownership migration:

1. Adds nullable `company_id` (and related) columns.
2. Assigns the legacy global settings row only when exactly one company exists.
3. Backfills persons from linked `employees.company_id`.
4. Backfills devices/groups when a single trusted settings company exists.
5. Backfills events from trusted person/device ownership.
6. Adds composite unique indexes.

Unresolved rows keep `company_id = null`. They stay excluded from company views and are never assigned by matching employee or person names. Reconcile them manually before treating isolation as complete for historical data.
