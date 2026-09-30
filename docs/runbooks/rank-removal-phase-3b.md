# Rank removal production runbook (Phase 3B)

## Before deploy

1. Take a full database backup. Phase 3B is irreversible without restore.
2. Run the read-only readiness check:

```bash
php artisan master-data:rank-removal-readiness
```

Expected:

```text
Ready for Rank removal: YES
```

If the output says `NO`:

```text
STOP
Do not run the destructive migration.
```

Resolve every reported gap (missing `position_id`, unmapped saved-view `rank_id` filters, broken mappings, document Rank pivots without Position equivalents). Never delete Rank data manually to bypass readiness.

3. Deploy application code that includes Phase 3B migrations and Position-only application paths.

4. Run migrations through the normal deployment process (`php artisan migrate`). Migration `2026_09_30_220000_remove_rank_schema_phase_3b` repeats readiness guards and then drops Rank schema.

## After deploy

Confirm:

- `ranks` table absent
- `rank_position_mappings` absent
- `document_requirement_rank` absent
- no live application `rank_id` columns remain

## Rollback

Restore the database backup and redeploy the previous application version. Do not rely on migration `down()` — Phase 3B is intentionally irreversible.
