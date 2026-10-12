# Runbook — Crew Scheduled Movements

## Production cron (Hostinger / shared hosting)

Hostinger and similar shared hosts typically expose only cron, not a long-lived queue worker. Configure **one** cron entry that runs every minute:

```bash
* * * * * cd /home/USER/path/to/oms-hrm && php artisan schedule:run >> /dev/null 2>&1
```

Registered task:

```text
crew:process-scheduled-movements  everyMinute  withoutOverlapping(5)
```

This path executes due movements **inline** during `schedule:run`. A long-lived queue worker is **not** required for scheduling. Verify after deploy:

```bash
php artisan schedule:list | grep crew:process-scheduled-movements
php artisan crew:process-scheduled-movements --limit=5
```

## Database timezone and migration notes

- `crew_scheduled_movements` stores schedule/execution timestamps as **UTC DATETIME** (not MySQL `TIMESTAMP`), so connection session timezones cannot rewrite values.
- Application `APP_TIMEZONE` may remain company-default (e.g. `Asia/Dubai`); scheduled columns hydrate as UTC via `UtcDateTimeCast`.
- After deploy, run migrations normally (`php artisan migrate --force`). No destructive refresh.

### Legacy / pre-UTC rows (PR #209 hardening)

Phase 2 landed UTC storage before production merge. Environments that temporarily stored **company-local naive walls** under an earlier PR build must not silently reinterpret digits as UTC.

Safe options:

1. **Preferred (unreleased / test data):** cancel unresolved schedules and recreate them after deploying the UTC build.
2. **If local walls must be kept:** convert with the row’s `scheduled_timezone`, e.g. interpret stored digits in that IANA zone and rewrite UTC (`CONVERT_TZ` / application script). Do **not** run a blind “subtract 4 hours” migration across mixed timezones.
3. Never change `APP_TIMEZONE` or MySQL `time_zone` as a substitute for fixing stored values.

Fresh installs that only run the current create migration already store UTC and need no backfill.

## Health checks

```bash
# Due / needs-attention inventory (read-only; scheduled_at shown as UTC)
# Prefer --company in multi-tenant production support.
php artisan crew:recover-scheduled-movements --list --company=123

# Recover stale processing rows for one company (modifies status)
php artisan crew:recover-scheduled-movements --company=123

# Recover and optionally process due items for one company
php artisan crew:recover-scheduled-movements --company=123 --process-due

# Without --company: operates across all companies (support-wide default)
php artisan crew:recover-scheduled-movements --list

# Bounded process run
php artisan crew:process-scheduled-movements --limit=25
```

`--list` is strictly read-only: it never recovers stale processing rows and ignores `--process-due`. Output states whether rows were inspected or modified. `--company` applies to list, recovery, and optional due-processing.

## Timestamp policy

| Field | Meaning |
|-------|---------|
| `scheduled_at` | Intended future instant stored in **UTC**; operators enter company-local time |
| `scheduled_timezone` | IANA zone used for local input/display |
| `executed_at` | UTC instant when **this** movement successfully ran (fresh per row in a batch) |
| `effective_occurred_at` | UTC operational occurrence (company-local wall applied to the phase) |
| `processing_started_at` | UTC claim time for this movement |

DST gaps and fall-back overlaps are rejected at schedule/create/update time. If a due schedule is processed more than **15 minutes** late, it becomes `needs_attention` with `lateness_exceeded` and is **not** backdated.

## Operator recovery

1. Open the assignment → Scheduled Movement card (Needs Attention reason shown)
2. Either **Edit / Reschedule** (full movement fields) / **Quick reschedule**, **Cancel**, or use **Record Now** for the actual historical/current movement
3. Authorized notification recipients receive Crew Operations alerts when Needs Attention is detected (toggle under Crew Settings; reconcile cadence ~10 minutes)
4. Do not invent a product UI “confirm due schedule” action — automatic execution only

## Permissions seeder

After deploy:

```bash
php artisan migrate --force
php artisan db:seed --class=PermissionsSeeder
```

## Known limitations

- One unresolved schedule per assignment (v1)
- Minute-accurate execution depends on host cron reliability
- System execution uses `actor_id = null` and audit property `executor=system_automatic`
- Alert delivery follows the existing 10-minute Crew Operations reconciliation cadence (not instant on failure)
