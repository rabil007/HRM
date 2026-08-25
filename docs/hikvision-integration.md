# Hikvision integration

Hikvision is a **company-owned** integration. Settings, devices, person groups, persons, and access events belong to one OMS company.

## Configuration location

Configure credentials and schedules under:

**Company Settings → Integrations → Hikvision** (`/settings/integrations/hikvision`)

Select the company first. Switching companies loads that company’s Hikvision configuration only. Hikvision fields live in `hikvision_settings`, not on the `companies` table. Each company has at most one settings row (`unique(company_id)`).

Opening the settings page is read-only: it does not create or restore settings rows. Soft-deleted settings stay deleted. Saving new settings (permission `settings.integrations.hikvision.update`) creates a clean row and permanently replaces any soft-deleted row for that company—without reusing old credentials, tokens, schedules, or sync state. Webhook registration never restores deleted settings.

## Credentials and secrets

API key, API secret, and webhook verify token are encrypted at rest. A company is configured only when it has its own stored host, key, and secret and the integration is enabled. Global `.env` / `config/hikvision.php` credentials are never used at runtime for company integrations.

Settings pages return masked empty fields with `has_api_key`, `has_api_secret`, and `has_webhook_verify_token`. Blank credential submissions preserve stored values. Decrypted secrets are never logged.

## Webhooks

Each settings row has a non-sequential `public_id`. The callback URL is:

`/integrations/hikvision/webhook/{publicIntegrationId}`

**Trust model**

| Source | Role |
| --- | --- |
| Hik-Connect webhook | Authenticated **notification / trigger only** |
| Hikvision OpenAPI / ISAPI fetch | **Authoritative** access-event and attendance source |

Webhook POST bodies are **not** cryptographically bound to the vendor `timestamp.batchId` HMAC and are never upserted into attendance-eligible `hikvision_access_events` or used to sync attendance directly. A successful webhook runs the same fetch lifecycle as manual/scheduled dispatchers (`resolveStaleEventsFetch` → skip if already queued/running → `beginEventsFetch` → `FetchHikvisionAccessEventsJob` with origin `webhook_trigger`). After a successful webhook-triggered fetch, attendance is synchronized for **that company date only** — the coordinator’s “today also rebuilds yesterday” backfill is not used, so historical webhook-only punches cannot be recalculated to absent.

Webhook bursts are coalesced with a settings+date cache key (~60s TTL). Coalesced notifications set a pending flag and schedule one delayed trailing fetch after the debounce window so the last punch in a burst still causes an authoritative API pull. This debounce/trailing path is webhook-specific and does **not** affect manual/scheduled/catch-up fetches. Historical `event_source = webhook` rows are retained but excluded from attendance (`accessRecords`).

Processing resolves only integrations that are webhook-enabled, company-owned, and API-configured (`isConfigured()`). Signature failures, disabled integrations, unconfigured credentials, and orphan (`company_id` null) rows all return a generic 404. Payload `company_id` values are ignored. The GET verification handshake and existing HMAC format are unchanged.

## Scheduled jobs, reconciliation, and stabilization window

The scheduler runs fetch commands every minute:

1. **Daily reconciliation and stabilization replay (`hikvision:fetch-access-events`)**:
   - Dispatches once the company's configured schedule time (`events_fetch_schedule_at`, default `18:00`) has passed in the company's operational timezone.
   - Reconciles yesterday's access records and attendance.
   - Implements a **rolling lookback and stabilization window** (`hikvision.reconciliation_lookback_days`, default 3 days).
   - **Stabilization Replay:** Because Hik-Connect mobile attendance is eventually consistent, a `completed` reconciliation status indicates that processing succeeded, **not** that Hikvision has finalized all late mobile data. Therefore, all target dates inside the lookback window are safely re-evaluated and re-fetched on subsequent daily reconciliation cycles (at most once per cycle per date).
   - **Chronological & Prioritized Dispatch:** Unprocessed or failed dates are dispatched first in chronological order, followed by previously completed stabilization replays.
   - **Idempotency:** Repeated fetches are fully idempotent. Newly arrived mobile events update existing attendance records and source badges without duplicating events or records. Manual HR attendance records (`source = manual`) are never overwritten.
   - **Historical Boundaries:** Dates older than the configured lookback window exit automatic stabilization and require manual recovery via the Access Events page if historical gaps exist.
   - Includes stale fetch protection (auto-resolving abandoned queued/running statuses after 5 minutes) and bounded scheduler overlap protection (`withoutOverlapping(15)`).

2. **Same-day evening fetch (`hikvision:fetch-todays-access-events`)**:
   - Dispatches at the configured evening schedule time (`events_evening_fetch_schedule_at`, default `20:00`) in the company timezone.
   - Best-effort pull of today's access control events.
   - Does **not** mark the date as final reconciliation because mobile app check-in/out records can be delayed by Hik-Connect and processed later. Next-day reconciliation and subsequent stabilization replays remain the authoritative recovery mechanism.
   - No assumptions are made regarding fixed mobile data processing hours (e.g. 18:00, 19:00, or 1 hour post-punch).

3. **Manual fetch**:
   - Administrative recovery action on the Access Events page (`/hikvision/access-events`).
   - Allows on-demand fetching and recalculation for any selected date.
   - Distinguishable in `job_runs` via explicit `fetch_origin` tracking (`manual`, `scheduled_today`, `scheduled_reconciliation`, `catch_up`).

## Troubleshooting and Operations

- **Stale Scheduler Mutexes:** If a scheduler dispatcher is suspected of being blocked by an abandoned lock, run `php artisan schedule:clear-cache` during a controlled maintenance window. Do not run this unconditionally during routine deployments as it clears mutex locks for all scheduled application tasks.

## Tenant isolation

All list, sync, link, filter, and export operations scope by the active `current_company_id`. Cross-company employee–person links and person mutations are rejected. **New** employee links require an active employee in the current company; existing links are not cleared when an employee later becomes inactive. Historical access-event `company_id` does not change when an employee is relinked or moved. New persons, devices, groups, and events always require a positive `company_id`. See [Active employee visibility](./architecture/active-employee-visibility.md).

## Legacy backfill

The ownership migration may leave unresolved historical rows with `company_id = null`. Those rows stay excluded from company views, webhooks, and jobs, and are never assigned by matching names. Reconcile them manually before treating isolation as complete for historical data.
