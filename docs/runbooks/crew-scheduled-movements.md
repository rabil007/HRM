# Runbook — Crew Scheduled Movements

## Production cron

Ensure the application scheduler runs at least once per minute:

```bash
* * * * * cd /path/to/oms-hrm && php artisan schedule:run >> /dev/null 2>&1
```

Registered task:

```text
crew:process-scheduled-movements  everyMinute  withoutOverlapping(5)
```

This path executes due movements **inline** during `schedule:run`. A long-lived queue worker is not required for scheduling.

## Database timezone notes

- `crew_scheduled_movements` stores schedule/execution timestamps as **UTC DATETIME** (not MySQL `TIMESTAMP`), so connection session timezones cannot rewrite values.
- Application `APP_TIMEZONE` may remain company-default (e.g. `Asia/Dubai`); scheduled columns still hydrate as UTC via `UtcDateTimeCast`.
- After deploy, run migrations normally (`php artisan migrate --force`). No destructive refresh.

## Health checks

```bash
# Due / needs-attention inventory (scheduled_at shown as UTC)
php artisan crew:recover-scheduled-movements --list

# Recover stale processing rows and optionally process due items
php artisan crew:recover-scheduled-movements --process-due

# Bounded process run
php artisan crew:process-scheduled-movements --limit=25
```

## Timestamp policy

| Field | Meaning |
|-------|---------|
| `scheduled_at` | Intended future instant stored in **UTC**; operators enter company-local time |
| `scheduled_timezone` | IANA zone used for local input/display |
| `executed_at` | UTC instant when the processor successfully ran |
| `effective_occurred_at` | UTC operational occurrence (company-local wall applied to the phase) |
| `processing_started_at` | UTC claim time |

If a due schedule is processed more than **15 minutes** late, it becomes `needs_attention` with `lateness_exceeded` and is **not** backdated.

## Operator recovery

1. Open the assignment → Scheduled Movement card (Needs Attention reason shown)
2. Either **Edit / Reschedule** (full movement fields) / **Quick reschedule**, **Cancel**, or use **Record Now** for the actual historical/current movement
3. Authorized notification recipients receive Crew Operations alerts when Needs Attention is detected (toggle under Crew Settings)
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
- Alert delivery follows the existing 10-minute Crew Operations reconciliation cadence
