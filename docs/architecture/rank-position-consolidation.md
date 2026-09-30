# Rank → Position Consolidation

## Current state

**Position is the only occupational catalog. Rank has been retired (Phase 3B complete).**

---

## Phase 1 — Completed (data foundation)

Prepared OMS-HRM so Position can become the single occupational master without cutting application behaviour yet.

### Delivered

| Area | Phase 1 addition |
| --- | --- |
| Position | `is_crew_position`, `max_tour_of_duty_days` |
| Mapping | tenant-aware `rank_position_mappings` (`company_id + rank_id → position_id`) |
| Employees / crew tables | nullable `position_id` backfilled beside Rank |
| Document requirements | Rank pivots copied into Position pivots (Rank retained) |
| Command | `master-data:prepare-rank-position-consolidation` (dry-run + `--apply`) |

### Mapping rules (still apply)

- Exact normalized title only (trim, collapse whitespace, case-insensitive)
- Never map a Rank to another company’s Position
- Soft-deleted mapping targets are reported, not auto-restored
- Near-duplicates are report-only

Implementation: `App\Support\MasterData\PrepareRankPositionConsolidation`.

---

## Phase 2 — Completed (application cutover)

Position is the **canonical application source of truth** for active behaviour:

- Employee create/edit/profile/directory/filters/search/imports/exports
- Crew Assignment lifecycle (create, update, join, transfer, redeploy, corrections, presenters)
- Crew Planning (assignments, relief, Gantt, vacant slots)
- Sea Service, Vessel Manning, Tour of Duty
- Document requirement matching (Position pivots for active resolution)
- Reports, saved views, smart search, activity presentation

### Compatibility helpers (retained until Phase 3)

- `App\Support\Positions\RankPositionBridge` — tenant mapping, crew position options, dual-write, batch `hydrateCanonicalPositions`
- `App\Support\Positions\LegacyRankFilterTranslator` — request/saved-view `rank_id` → `position_id`
- `App\Http\Requests\Organization\Concerns\TranslatesLegacyCrewRankToPosition` — legacy POST bodies with `rank_id` only

New URLs, filters, and saved views store **`position_id` only**. Legacy `rank_id` is accepted and translated when a company mapping exists. **Never treat a Rank ID as a Position ID.**

### Presenter contract (Phase 2 cleanup)

`CrewAssignmentPresenter` uses only an already-loaded `position` relation (or Positions set by `RankPositionBridge::hydrateCanonicalPositions`). It must not lazy-load `position` or run per-record relation queries. Relief context exposes `source_position` (not Rank) when the source assignment Position was eager-loaded/hydrated.

### Temporary compatibility retained until Phase 3

- Physical schema: `ranks`, `rank_id` columns, `document_requirement_rank`, `rank_position_mappings`
- Legacy `?rank_id=` URL / saved-view / import column translation
- Dual-write of mapped `rank_id` when schema still requires it
- Historical activity / correction payloads containing Rank remain readable

### Not done in Phase 2

- Dropping `ranks` / `rank_id` / Rank pivots
- Removing Rank master-data UI/routes/permissions
- Destructive cleanup of unresolved `rank_id` without `position_id`

---

## Phase 3A — Completed (final active Rank contract removal)

Active application behaviour is fully Position-canonical. Rank remains only for temporary compatibility / schema pending destructive Phase 3B.

### Delivered

| Area | Phase 3A change |
| --- | --- |
| Current Crew export | Heading + mapped value use Position (`position.name`); no Rank heading |
| Current Crew vessel sorting | Sort by hydrated `position.title` after batch hydrate |
| Sea Service CSV/XLSX/PDF | Position heading + canonical title; legacy Rank-only rows via bridge hydrate |
| Sea Service active UI | Position labels / `position_name`; wording uses “position” not “rank” |
| Crew Planning Gantt | `position_id` / `position_name`, groups under `positions`, row keys `vessel:<id>|position:<id>` |
| Crew Planning relief | `relieves_position_name` |
| Crew Planning drag/drop | `positionId` / `positionName` |
| Projection overlay contract | Presenter emits Position IDs/names (maps legacy Rank keys from manning query) |

### Temporary compatibility retained

- Physical `rank_id` columns and `ranks` / `rank_position_mappings` tables
- Legacy `?rank_id=` URL translation and dual-write helpers
- Historical import Rank column parsing
- Legacy Gantt row keys `vessel:<id>|rank:<rank_id>` may still be *accepted* only where callers translate through the mapping bridge; **new** keys always use `position:`

### Not done in Phase 3A (destructive Phase 3B)

- Dropping `ranks` / `rank_id` / Rank pivots / mappings
- Removing Rank master-data UI/routes/permissions
- Converting every remaining Crew Operations alert/dashboard internal Rank key (outside Planning Gantt contract)

---

## Phase 3B — Completed (destructive Rank removal)

Position is the **only** occupational/job-role catalog. Rank has been retired.

### Field semantics (may differ per record)

| Field | Meaning |
| --- | --- |
| `Employee.position_id` | Current HR Position |
| `CrewAssignment.position_id` | Assignment role |
| `CrewPlanningAssignment.position_id` | Planned role |
| `EmployeeSeaService.position_id` | Historical service role |
| `VesselManning.position_id` | Required vessel role |

### Delivered

- `CrewProjectedManningQuery` is Position-native
- Saved-view `rank_id` filters migrated to `position_id` (migration A)
- Destructive guarded schema removal (migration B): drops `document_requirement_rank`, all live `rank_id` FKs/columns, `rank_position_mappings`, `ranks`, and Rank master-data permissions
- Rank master-data UI/routes/controllers removed
- Readiness command: `php artisan master-data:rank-removal-readiness` (read-only; non-zero when unsafe)
- Production runbook: `docs/runbooks/rank-removal-phase-3b.md`

### Destructive migration policy

Rollback requires **database backup restore + previous application version**. Migration `down()` throws and does not recreate Rank data.

Historical activity/correction payloads may still contain legacy `rank_id` / `rank_name` snapshot fields for display. New events use Position only. Do not query the removed `ranks` table to render history.
