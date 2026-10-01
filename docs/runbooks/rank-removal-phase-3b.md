# Rank removal production runbook (Phase 3B)

## Reality check

PR #162 contains **every** Rank→Position phase in a single deployable unit.
Production has **not** previously run `master-data:prepare-rank-position-consolidation --apply`.

Do **not** run a single unattended `php artisan migrate` that collapses preparation and
destructive Rank removal without a readiness checkpoint.

## Safe one-PR deployment sequence

1. Put the application into maintenance mode / prevent writes.

2. Take a full production database backup. Phase 3B is irreversible without restore.

3. Deploy PR #162 application code **without** exposing normal traffic yet.

4. Run the **non-destructive** Phase 1 / preparation migrations only:

```bash
php artisan migrate --path=database/migrations/2026_09_30_150000_add_crew_fields_to_positions_table.php --force
php artisan migrate --path=database/migrations/2026_09_30_150001_create_rank_position_mappings_table.php --force
php artisan migrate --path=database/migrations/2026_09_30_150002_add_position_id_to_crew_rank_tables.php --force
php artisan migrate --path=database/migrations/2026_09_30_200000_backfill_rank_to_position_before_removal.php --force
```

The `200000` migration automatically:

- builds tenant Rank→Position mappings (`exact` / `created`)
- backfills null operational `position_id` values (including soft-deleted rows)
- copies `document_requirement_rank` into `document_requirement_position`

It never overwrites an existing `position_id` and never drops Rank schema.

5. Inspect readiness:

```bash
php artisan master-data:rank-removal-readiness
```

Expected:

```text
Ready for Rank removal: YES
```

6. If the output says `NO`:

```text
STOP
Do not run the destructive migration.
```

Resolve every reported gap (missing `position_id`, Rank/Position conflicts, cross-company
Positions, soft-deleted/missing Positions, unmapped or conflicting saved-view filters,
broken mappings, missing document Position pivots). Re-run readiness until YES.

7. Convert persisted saved-view Rank filters:

```bash
php artisan migrate --path=database/migrations/2026_09_30_210000_migrate_saved_view_rank_filters_to_position.php --force
```

If this migration throws (for example a Rank/Position filter conflict), stop and repair the
listed saved-view ID before continuing.

8. Re-confirm readiness:

```bash
php artisan master-data:rank-removal-readiness
```

9. Run the final destructive Rank-removal migration:

```bash
php artisan migrate --path=database/migrations/2026_09_30_220000_remove_rank_schema_phase_3b.php --force
```

This migration still calls `RankRemovalGuards::assertReadyOrFail()` before the first DROP.
Defense in depth: even if step 5/8 was skipped, unresolved coverage aborts destructive DDL.

10. Verify final schema:

- `ranks` table absent
- `rank_position_mappings` absent
- `document_requirement_rank` absent
- no live application `rank_id` columns remain

11. Run smoke checks (login, employees, crew assignments, planning, sea services, manning).

12. Re-enable application traffic / exit maintenance mode.

## Fresh installs

A completely fresh database may run:

```bash
php artisan migrate
```

The automatic backfill is a no-op (or harmless) when there is no legacy Rank data, then
Phase 3B removes the temporary Rank schema. Final schema remains Position-only.

## Rollback

Restore the database backup and redeploy the previous application version.
Do not rely on migration `down()` — Phase 3B is intentionally irreversible.
