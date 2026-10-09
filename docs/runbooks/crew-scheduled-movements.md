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

## Health checks

```bash
# Due / needs-attention inventory
php artisan crew:recover-scheduled-movements --list

# Recover stale processing rows and optionally process due items
php artisan crew:recover-scheduled-movements --process-due

# Bounded process run
php artisan crew:process-scheduled-movements --limit=25
```

## Timestamp policy

| Field | Meaning |
|-------|---------|
| `scheduled_at` | Intended future time (company TZ stored as UTC) |
| `executed_at` | When the processor successfully ran |
| `effective_occurred_at` | Operational occurrence applied to the phase (timely runs use execution time) |

If a due schedule is processed more than **15 minutes** late, it becomes `needs_attention` with `lateness_exceeded` and is **not** backdated.

## Operator recovery

1. Open the assignment → Scheduled Movement card (Needs Attention reason shown)
2. Either **Reschedule** to a new future time, **Cancel**, or use **Record Now** for the actual historical/current movement
3. Do not invent a product UI “confirm due schedule” action — automatic execution only

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
