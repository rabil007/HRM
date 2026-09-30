# Rank → Position Consolidation

## Phase 1: dual-schema preparation

This phase prepares OMS-HRM to make `Position` the single occupational/job master across Employees, Crew Assignments, Crew Planning, Sea Service, Vessel Manning, Tour of Duty, Document Requirements, and related reports/filters/imports.

**Phase 1 does not cut the application over from Rank to Position.**

Rank tables, columns (`rank_id`), routes, UI, permissions, and Crew Operations behaviour remain fully functional. The only intentional user-visible change is the new crew/Tour-of-Duty fields on Position master data.

## Why both IDs temporarily exist

During the transition:

| Area | Current | Phase 1 addition |
| --- | --- | --- |
| Employees | `rank_id` + `position_id` | reconcile `position_id` carefully (including soft-deleted employees) |
| Crew Assignment / Planning / Sea Service / Vessel Manning | `rank_id` | nullable `position_id` backfilled beside Rank |
| Document requirements | Rank + Position pivots | copy Rank pivots into Position pivots without removing Rank |
| Mapping | — | tenant-aware `rank_position_mappings` |

`Position` will become the future source of truth in a later phase. Rank must not be removed until that cutover is complete and validated.

## Position crew capabilities

`positions` now supports:

- `is_crew_position` — boolean, **default `true`**
- `max_tour_of_duty_days` — nullable unsigned small integer (same 1–365 validation as Rank)

These fields are editable in Organization → Positions. Disabling “Available for Crew Operations” does not change current Crew forms (still Rank-based); it prepares filtering for Phase 2.

## Mapped Position deletion protection

`MasterDataUsage` treats `rank_position_mappings.position_id` as Position usage (`Rank consolidation mappings`).

If a Position participates in an active Rank→Position mapping, Position deletion is blocked. This prevents soft-deleting a Position that consolidation still depends on, even when no employee/crew row references it yet.

## Mapping rules (conservative)

Automatic matching is exact normalized title only:

- trim edges
- collapse repeated whitespace
- case-insensitive compare

No fuzzy similarity, abbreviation expansion, or punctuation stripping for automatic mapping.

Mappings are always:

```text
company_id + rank_id → position_id
```

A Rank is global; Position is company-scoped. Never map a Rank to another company’s Position.

Match types:

- `exact` — existing company Position matched by normalized title
- `created` — new company Position created from Rank
- `manual` — reserved for explicit future aliases

## Soft-deleted mapping targets

If an existing `rank_position_mappings.position_id` points to a soft-deleted Position:

- the command reports `mapped_position_soft_deleted`
- it does **not** auto-restore the Position
- it does **not** create a replacement Position
- it does **not** create a duplicate `company_id + rank_id` mapping
- the command exits with failure until an administrator resolves it explicitly

Integrity types are distinct:

- `mapping_position_missing`
- `mapping_position_company_mismatch`
- `mapped_position_soft_deleted`

## Near duplicates (report only)

The command may surface likely aliases such as:

- `PTWC` ↔ `PTW Coordinator`
- `Rigger Leaderman` ↔ `Rigging Leaderman`
- `Port Captain` ↔ `Port Captain - MSI`

These are **report-only**. Similarity never writes `rank_position_mappings`.

## Active Rank → inactive Position

Exact title matches may still create/keep a mapping when Rank is active and Position is inactive.

That mismatch is reported under `status_conflicts` for manual review before Phase 2. Position status is never auto-reactivated.

## Soft-deleted employees

Employee Rank→Position reconciliation includes soft-deleted employees (`Employee::withTrashed()`).

- null `position_id` is backfilled from the company mapping
- conflicts are reported and not overwritten
- `deleted_at`, employee status, and unrelated fields are left unchanged

## Command

Dry-run (default — **zero writes**):

```bash
php artisan master-data:prepare-rank-position-consolidation
php artisan master-data:prepare-rank-position-consolidation --company=1
```

Apply (idempotent; safe to re-run before Phase 2):

```bash
php artisan master-data:prepare-rank-position-consolidation --apply
php artisan master-data:prepare-rank-position-consolidation --apply --company=1
```

Implementation:

- `App\Support\MasterData\PrepareRankPositionConsolidation`
- `App\Console\Commands\PrepareRankPositionConsolidationCommand`

## Reviewing conflicts

The report surfaces:

- employee Position/Rank conflicts (never overwritten)
- ambiguous normalized Position candidates (never auto-picked)
- Tour-of-Duty conflicts (existing non-null Position TOD preserved)
- status conflicts (active Rank → inactive Position)
- mapped soft-deleted Positions (integrity failure; command fails)
- near-duplicate candidates (reported only; not merged)
- unmapped Rank references
- tenant integrity failures

Resolve conflicts manually before Phase 2. Do not treat near-duplicates such as `PTWC` / `PTW Coordinator` as aliases unless explicitly approved.

## Idempotency

Re-running `--apply`:

- does not recreate existing `rank_position_mappings`
- does not recreate Positions for soft-deleted mapping targets
- fills only null `position_id` values
- does not overwrite conflicting `position_id` values
- uses `syncWithoutDetaching` for document Position requirements (no duplicates)
- continues to report unresolved integrity/status/near-duplicate findings

## Out of scope for Phase 1

Crew Assignment / Planning / Sea Service / Vessel Manning forms, TOD resolver, crew reports/filters/corrections, historical crew import, Employee Rank input, Rank master-data page/routes/permissions, and deletion of Rank schema remain Rank-based until Phase 2.
