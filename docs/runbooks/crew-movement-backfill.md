# Crew Movement Legacy Backfill Runbook

Convert existing `EmployeeDeployment` rows into `CrewAssignment` / `CrewAssignmentPhase` without modifying deployments.

Dual-write is **not** enabled. The Crew Deployments module continues to operate independently.

## Pre-backfill backup

1. Take a database backup (or snapshot) covering `employee_deployments`, `crew_assignments`, and `crew_assignment_phases`.
2. Confirm Phase 0/1 migrations have been applied, including unique link indexes.
3. Do **not** run `--commit` until dry-run conflicts are reviewed.

## Dry-run procedure

```bash
php artisan crew-movements:backfill
php artisan crew-movements:backfill --company=5
php artisan crew-movements:backfill --company=5 --limit=100 --report=storage/app/reports/crew-backfill-company-5.json
```

Confirm the console prints `DRY RUN`. No assignment/phase rows should be inserted.

## Reviewing conflicts

Inspect the JSON report `rows` where `result` is `conflict` or `failed`.

Common reasons:

- Employee/company mismatch
- Existing active crew assignment
- Impossible date ordering (join after disembark, travel before disembark, etc.)
- No inferable phases
- Linked planning assignment mismatch

Do not manually “fix” source deployments during backfill unless product owners approve a separate data-cleanse.

## Small-company pilot

1. Dry-run one low-risk company with `--company=` and `--report=`.
2. Spot-check eligible mappings against live deployments.
3. Commit only that company:

```bash
php artisan crew-movements:backfill --company=5 --commit --report=storage/app/reports/crew-backfill-company-5-commit.json
```

4. Re-run the same command; expect `already_migrated`, not duplicates.

## Commit-mode command

```bash
php artisan crew-movements:backfill --company=5 --commit
php artisan crew-movements:backfill --deployment=120 --commit
```

One transaction per deployment. Non-fatal conflicts continue processing.

## Verification queries

```sql
SELECT COUNT(*) FROM crew_assignments WHERE source = 'legacy_deployment';
SELECT employee_deployment_id, COUNT(*)
FROM crew_assignments
WHERE employee_deployment_id IS NOT NULL
GROUP BY employee_deployment_id
HAVING COUNT(*) > 1;
SELECT ca.id, ca.assignment_no, ca.status, ed.id AS deployment_id
FROM crew_assignments ca
JOIN employee_deployments ed ON ed.id = ca.employee_deployment_id
WHERE ca.company_id = 5;
```

Confirm `employee_deployments` row counts and critical date columns are unchanged.

## Rollback considerations

- Soft-delete or hard-delete created `crew_assignments` (phases cascade/FK as defined) for a failed pilot company if needed.
- Never delete or rewrite `employee_deployments` as a rollback of backfill.
- Restore from backup if unique indexes or mass incorrect mappings were committed without review.

## Post-backfill validation

- Re-run dry-run / commit on the same scope → predominantly `already_migrated`.
- Existing Crew Deployments UI and sea-service sync still work.
- Assignment numbers for legacy rows remain `LEGACY-{deployment_id}`.

## Date normalization

Legacy date-only fields are interpreted as **company timezone start-of-day**, then stored as timestamps according to application DB conventions. Do not mix server timezone for these conversions.
