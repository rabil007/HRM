# Rank → Position Consolidation

## Phase 1: dual-schema preparation

This phase prepares OMS-HRM to make `Position` the single occupational/job master across Employees, Crew Assignments, Crew Planning, Sea Service, Vessel Manning, Tour of Duty, Document Requirements, and related reports/filters/imports.

**Phase 1 does not cut the application over from Rank to Position.**

Rank tables, columns (`rank_id`), routes, UI, permissions, and Crew Operations behaviour remain fully functional. The only intentional user-visible change is the new crew/Tour-of-Duty fields on Position master data.

## Why both IDs temporarily exist

During the transition:

| Area | Current | Phase 1 addition |
| --- | --- | --- |
| Employees | `rank_id` + `position_id` | reconcile `position_id` carefully |
| Crew Assignment / Planning / Sea Service / Vessel Manning | `rank_id` | nullable `position_id` backfilled beside Rank |
| Document requirements | Rank + Position pivots | copy Rank pivots into Position pivots without removing Rank |
| Mapping | — | tenant-aware `rank_position_mappings` |

`Position` will become the future source of truth in a later phase. Rank must not be removed until that cutover is complete and validated.

## Position crew capabilities

`positions` now supports:

- `is_crew_position` — boolean, **default `true`**
- `max_tour_of_duty_days` — nullable unsigned small integer (same 1–365 validation as Rank)

These fields are editable in Organization → Positions. Disabling “Available for Crew Operations” does not change current Crew forms (still Rank-based); it prepares filtering for Phase 2.

## Mapping rules (conservative)

Automatic matching is exact normalized title only:

- trim edges
- collapse repeated whitespace
- case-insensitive compare

No fuzzy similarity, abbreviation expansion, or punctuation stripping.

Mappings are always:

```text
company_id + rank_id → position_id
```

A Rank is global; Position is company-scoped. Never map a Rank to another company’s Position.

Match types:

- `exact` — existing company Position matched by normalized title
- `created` — new company Position created from Rank
- `manual` — reserved for explicit future aliases

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
- near-duplicate candidates (reported only; not merged)
- unmapped Rank references
- tenant integrity failures

Resolve conflicts manually before Phase 2. Do not treat near-duplicates such as `PTWC` / `PTW Coordinator` as aliases unless explicitly approved.

## Idempotency

Re-running `--apply`:

- does not recreate existing `rank_position_mappings`
- fills only null `position_id` values
- does not overwrite conflicting `position_id` values
- uses `syncWithoutDetaching` for document Position requirements (no duplicates)

## Out of scope for Phase 1

Crew Assignment / Planning / Sea Service / Vessel Manning forms, TOD resolver, crew reports/filters/corrections, historical crew import, Employee Rank input, Rank master-data page/routes/permissions, and deletion of Rank schema remain Rank-based until Phase 2.
