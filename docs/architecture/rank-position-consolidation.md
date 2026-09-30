# Rank → Position Consolidation

## Current state

**Position is now canonical for active application behavior.**

Phase 1 (data foundation) and Phase 2 (application cutover) are **complete**. Rank remains only for:

- temporary legacy compatibility (dual-write, URL/import/filter translation)
- historical persisted payload readability (activity, corrections)
- remaining physical schema pending Phase 3
- legacy Rank master-data UI/routes/permissions until Phase 3 deletion

Do **not** treat current Crew Assignment, Crew Planning, Sea Service, Vessel Manning, or Tour of Duty as Rank-canonical. Active forms, filters, reports, and presenters resolve and display **Position**. Rank has **not** been deleted yet.

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

## Phase 3B — Pending (destructive Rank removal)

Final Rank dependency audit, then remove legacy Rank schema/code/UI/routes/permissions after readiness reporting shows clean mapping coverage.

### Classification legend

| Code | Meaning |
| --- | --- |
| **A** | Phase 3B deletion candidate |
| **B** | Temporary legacy compatibility (remove after dual-write/URL support ends) |
| **C** | Historical migration / consolidation command (keep or archive) |
| **D** | Historical activity/audit compatibility (read old Rank payloads) |
| **E** | Tests documenting legacy compatibility |
| **F** | Documentation |

### Phase 3 checklist (inventory — do not delete until audit is clean)

#### A — Deletion candidates

- [ ] Rank master-data UI: `resources/js/pages/settings/master-data/ranks.tsx`, settings nav / creatable registry entries
- [ ] Rank routes, `RankController`, Rank permissions / seeder entries
- [ ] `App\Models\Rank` and Rank factories once no FKs remain
- [ ] Document Rank pivots (`document_requirement_rank`) after Position-only resolution is exclusive
- [x] Crew Planning Gantt `relieves_rank_name` → `relieves_position_name` (Phase 3A)
- [ ] Remaining `orWhereHas('rank')` search paths once Position search covers the same (Sea Service search uses Position + legacy Rank-only fallback)
- [ ] Rank Tour-of-Duty import path / Rank-specific TOD admin if superseded by Position TOD
- [x] Current Crew export Position conversion (Phase 3A)
- [x] Sea Service export Position conversion (Phase 3A)
- [x] Sea Service active UI terminology (Phase 3A)
- [x] Crew Planning Gantt Position contract (Phase 3A)
- [x] Crew Planning Position row keys (Phase 3A)
- [x] Crew Planning relief Position output (Phase 3A)
- [x] Crew Planning drag/drop Position contract (Phase 3A)

#### B — Temporary legacy compatibility

- [ ] `rank_id` columns on employees, crew_assignments, crew_planning_assignments, sea services, vessel manning, etc.
- [ ] `RankPositionBridge` dual-write + `rankIdForPosition` / `resolveCrewAssignmentPositionId`
- [ ] `LegacyRankFilterTranslator` and `TranslatesLegacyCrewRankToPosition`
- [ ] `rank_position_mappings` table + `RankPositionMapping` model
- [ ] Frontend deprecated `source_rank` / other Rank fallbacks marked Phase 3 compatibility
- [ ] Smart-search `rank` alias → `position_id`
- [ ] Historical Excel import Rank label resolution via bridge
- [ ] Correction field catalog / payloads still accepting or storing `rank_id` where dual-write requires it
- [ ] `CrewProjectedManningQuery` internal Rank keys (Planning presenter already maps to Position)

#### C — Historical migration

- [ ] `PrepareRankPositionConsolidation` command + support class (retain until post-cutover ops decide)
- [ ] Consolidation migrations that added `position_id` / mappings (do not reverse)

#### D — Historical activity/audit compatibility

- [ ] Activity change presentation that can display historical Rank labels from old payloads
- [ ] Approved correction snapshots that stored Rank fields

#### E — Tests documenting legacy compatibility

- [ ] Pest fixtures that still create Ranks + mappings (`crew-assignment-fixtures`, `rank-position-bridge-fixtures`, etc.)
- [ ] Feature tests asserting legacy `rank_id` URL/import translation
- [ ] Query-count tests that still eager-load `rank` beside `position`

#### F — Documentation

- [ ] This file — collapse Phase 1/2/3A history after Phase 3B ships
- [ ] Domain / runbook / report docs that still mention Rank filters as primary
- [ ] `docs/saved-views.md`, crew report docs, payroll notes referencing Rank

### Verification before Phase 3B deletion

```bash
php artisan master-data:prepare-rank-position-consolidation
# resolve unmapped ranks / integrity failures
php artisan test --compact tests/Feature/Positions/RankPositionPhase2ApplicationTest.php
php artisan test --compact tests/Feature/MasterData/RankPositionConsolidationTest.php
```

Phase 3B must not begin until a final dependency audit confirms no active Rank-canonical contracts remain.
