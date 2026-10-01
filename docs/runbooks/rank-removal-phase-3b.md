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

Exact title matches may set `is_crew_position=true` and fill Position TOD **only when
Position TOD is null**. If Rank TOD and Position TOD are both set and disagree, readiness
blocks later — values are not silently normalized. Active Rank → inactive Position is also
reported as a blocker (Positions are not auto-activated).

5. Inspect readiness:

```bash
php artisan master-data:rank-removal-readiness
```

At this stage readiness **may still report NO** solely because saved views still contain
`rank_id` (`Saved views still using rank_id`). That is expected before migration `210000`.

Other blockers that must also be zero before destructive removal:

| CLI label | Meaning | Operator action |
| --- | --- | --- |
| Saved views still using rank_id | Any saved view still persists `rank_id` | Run migration `210000` (below). Do not delete views to “clear” readiness. |
| Vessel Manning Position collisions | Multiple manning rows share `(company, vessel, position)` after backfill | Manually resolve which row should remain / how `required_count` should read. Do **not** auto-SUM/MAX. |
| Rank/Position TOD conflicts | Mapped Rank and Position both have TOD and disagree | Explicitly choose/align the correct `max_tour_of_duty_days` on Position (or Rank) before removal. |
| Rank/Position status conflicts | Active Rank mapped to inactive Position | Explicitly activate the Position or deactivate the Rank mapping intentionally. |

Also resolve existing categories (missing `position_id`, Rank/Position ID conflicts,
cross-company / soft-deleted Positions, unmapped saved-view ranks, broken mappings,
missing document Position pivots).

**Do not delete operational or historical rows just to make readiness pass.**

6. If the only remaining blocker is saved-view conversion (and there are no conflicts /
collisions / TOD / status issues):

```bash
php artisan migrate --path=database/migrations/2026_09_30_210000_migrate_saved_view_rank_filters_to_position.php --force
```

If this migration throws (for example a Rank/Position filter conflict), stop and repair the
listed saved-view ID before continuing.

7. Re-confirm readiness:

```bash
php artisan master-data:rank-removal-readiness
```

Required result before `220000`:

```text
Ready for Rank removal: YES
```

Including:

```text
Saved views still using rank_id: 0
Vessel Manning Position collisions: 0
Rank/Position TOD conflicts: 0
Rank/Position status conflicts: 0
```

8. Run the final destructive Rank-removal migration:

```bash
php artisan migrate --path=database/migrations/2026_09_30_220000_remove_rank_schema_phase_3b.php --force
```

This migration calls `RankRemovalGuards::assertReadyOrFail()` **before the first DROP**,
and re-checks Vessel Manning Position collisions immediately before replacing the
Rank unique index with the Position unique index.

9. Verify final schema:

- `ranks` table absent
- `rank_position_mappings` absent
- `document_requirement_rank` absent
- no live application `rank_id` columns remain

10. Run smoke checks (login, employees, crew assignments, planning, sea services, manning).

11. Re-enable application traffic / exit maintenance mode.

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
