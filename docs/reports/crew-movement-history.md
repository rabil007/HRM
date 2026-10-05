# Crew Movement History

Crew Movement History is the read-only management report for the complete crew mobilisation cycle.

- Crew Assignments is where operational movements are recorded.
- Crew Planning is where future vessel assignments are planned.
- Crew Movement History reports and exports the resulting assignment and phase history.

## Source of truth

Each row represents one `CrewAssignment`. Planned assignment dates come from the assignment, while actual movement dates come exclusively from ordered `CrewAssignmentPhase` records. Accommodation history comes from `CrewAccommodationStay`. Planning rows and `EmployeeSeaService` records are not used to reconstruct actual movement history when assignment/phase records already exist.

The report excludes soft-deleted phases through the standard `phases` relationship. It does not create, update, or delete operational data. Form-only temporary inputs (`no_hotel_accommodation`, `planned_signoff_choice`, `sync_training_to_employee_training`, travel-home `completion_intent`) are never stored as report columns — only the resulting authoritative domain state is shown.

## Modern and legacy phases

The normal report timeline (**Complete phase timeline**) shows only modern product-facing phases:

- P0 Pre-Mobilisation
- P2A Join Standby
- P2B Training
- P4 On Vessel
- P5 Demobilisation Standby
- P6 Home / Redeployment

Legacy phases P1 Travel In and P3 Ready to Join are never mixed into the modern lifecycle visually. When an assignment actually recorded legacy movement, the UI shows a separate **Legacy recorded phases** section containing only the recorded P1/P3 periods (sequence, planned/actual timestamps, status, days, and remarks preserved). The presenter still exposes the full chronological `phase_timeline` plus derived `modern_phase_timeline` / `legacy_phase_timeline` without duplicating queries.

Current Phase filters offer the same modern phase list. Bookmarked legacy `current_phase=p1` or `current_phase=p3` query values remain supported by the backend filter when present.

## Assignment master

Expanded detail includes assignment number and record ID, employee identity, rank, client/project, vessel, status, current phase, source label, remarks, started/closed timestamps, created/updated timestamps, and company timezone.

## Date mapping and timezone

| Report value | Source |
|---|---|
| Planned Arrival | Assignment `planned_arrival_at` |
| Planned Join | Assignment `planned_join_at` |
| Planned Sign-Off | Assignment `planned_signoff_at` (+ `planned_signoff_source` / override reason) |
| Planned Travel Home | Assignment `planned_travel_at` |
| Actual Arrival | First P2A `actual_start_at`, with legacy fallback to completed P1 `actual_end_at` |
| Actual Join | First P4 `actual_start_at` |
| Actual Disembarkation | Completed P4 `actual_end_at` |
| Actual Return Home | First P6 `actual_start_at` |
| Assignment Started / Closed | Assignment `started_at` / `closed_at` |

### Actual Arrival precedence

`CrewArrivalResolver` is authoritative for UI display, export, and Actual Arrival From/To filters:

1. Prefer the **first** P2A / Join Standby occurrence by `sequence` with `actual_start_at`.
2. Only when no P2A arrival exists, fall back to the **first** completed P1 / Travel In occurrence by `sequence` with `actual_end_at`.

Later repeated P2A or P1 occurrences never override the authoritative value. Filters apply `from`/`to` against that same resolved occurrence (not against any matching phase via loose `whereHas`).

If both P1 and P2A exist, P2A wins. Filtering on the legacy P1 end date must not match an assignment that already has a P2A arrival.

Planned values remain date-oriented. **Actual operational events preserve company-local date and time** (`Y-m-d H:i:s` wall clock in the company timezone). Expanded UI and exports use those timestamps; compact table cells may stay concise.

Provenance labels continue to use `CrewDateProvenance`. Planned Sign-Off is never presented as Actual Disembarkation. Browser/device timezone is never used.

## Repeated phases and training

Phase occurrences are ordered by `sequence`. Repeated P2A/P2B periods remain separate in the timeline and in exports. Each P2B training occurrence exposes provider, course, planned/actual windows, remarks, and whether an `EmployeeTraining` record is linked via `CrewAssignmentPhase::employeeTraining()`.

## Accommodation history

Dedicated Accommodation History uses `CrewAccommodationService::assignmentAccommodationSummary()` / `crew_accommodation_stays`. Show stay type, accommodation status, hotel, room type, check-in/out dates, open/completed, stay days, and starting phase when present.

- Hotel is shown only when a stay exists with `accommodation_status = hotel`.
- Explicit `no_accommodation` stays display as **No Accommodation**.
- Phase codes alone never invent hotel occupancy.
- Multiple stays are serialized; none are silently dropped.

## Tour of Duty & sign-off

Tour progress reuses `CrewTourProgress` (days onboard, remaining days, status). Report fields include tour of duty days, planned sign-off source/label, and manual override reason. Completed assignments freeze progress at disembarkation and avoid misleading active urgency wording when status is null.

## Linked assignment journey

Vessel transfer and redeployment create linked assignments (`previous_assignment_id`, `source`). The report keeps **one row per CrewAssignment** and exposes previous/next summaries with links to assignment show pages. Cross-company linked records are rejected.

Starting checkpoint and current phase are separate concepts on each linked summary:

- **Starting checkpoint** — first persisted `CrewAssignmentPhase` ordered by `sequence` on the linked assignment (for example Redeployment destination that began at P2A).
- **Current phase** — the assignment’s `currentPhase` (which may later be P4 even though the destination originally started at P2A).

The journey **Relationship** (Vessel Transfer / Redeployment) is shown at the Linked Assignment Journey section level. Each linked card’s **Assignment source** is that assignment’s own creation `source` (for example Manual on a previous assignment) and must not be labeled as the movement relationship.

Export includes Starting Checkpoint for the current row and serializes next-assignment starting/current checkpoints when present.

## Corrections

Approved corrections appear as metadata (`has_corrections`, `correction_count`, `last_corrected_at`). Pending proposals never change official report dates. Official values already reflect approved corrections.

## Needs Attention

Filter, summary card count, row badge, and expanded warning list all use authoritative `CrewMovementAttentionQuery` (including Tour of Duty rules). Active P4 is **not** flagged merely for being active longer than 14 days.

## Payroll calendar-day preview

Uses `CrewMovementHistoryPayrollDays` / `CrewPhasePayCategoryResolver` for Sign-On Standby, On Vessel, Sign-Off Standby, and total. This is a movement/calendar-day preview only — final payroll also depends on contracts and payroll-period eligibility.

## Filters and search

Supports identity, status, current phase, vessel, rank, client, source, needs attention, planned arrival/join/sign-off ranges, actual arrival/join/disembarkation ranges, assignment started/closed ranges, hotel, accommodation status, stay type, tour status, and correction flags.

Search may match assignment no, employee no/name, vessel, client, rank, previous/next assignment no, hotel, room type, and training provider/course — always company-scoped with `EmployeeVisibilityScope`.

## Presentation hierarchy

Crew Movement History separates fast operational history scanning from deep OMS audit details:

- **Crew Movement History summary (Default Table)** — an operator-friendly assignment and service history structured like an operational Excel `CREW HISTORY` sheet. It answers the primary operational questions at a glance:
  - *Who worked where?* (Employee No, Crew Name, Rank, Vessel, Client)
  - *When did they arrive?* (Arrival — authoritative actual arrival)
  - *When did they join the vessel?* (Joined Vessel — actual P4 join)
  - *When did they sign off / disembark?* (Sign-Off / Disembarked — actual P4 end, or `Ongoing` if active)
  - *When did they return home?* (Returned Home — actual P6 start, or `Redeployed` if transferred directly without returning home)
  - *How many days onboard?* (Vessel Days — authoritative P4 elapsed days)
  - *What is the current status?* (Assignment status badge, with `On Vessel` for active P4)
- **Expanded history** — complete OMS movement/audit record, organized in 9 structured sections:
  1. Assignment Summary
  2. Actual Movement
  3. Planned / Forecast Dates
  4. Movement Timeline
  5. Accommodation
  6. Training
  7. Assignment Links / Transfer / Redeployment
  8. Payroll Day Preview
  9. Corrections / Audit

### Date accuracy and provenance

Actual dates are derived strictly from authoritative movement phases:
- Forecast/planned dates are **never silently promoted** to actual history.
- `planned_signoff_at` must never appear as actual sign-off. If P4 is still active, sign-off is displayed as `Ongoing`.
- If a crew member is redeployed or transferred directly to another vessel without returning home, the outcome is clearly labeled `Redeployed` (with date), preserving true operational semantics.

## Vessel Service Period filter

In addition to granular date range filters, a primary quick filter for **Vessel Service Period** allows filtering assignments by actual P4 vessel period overlap:
- `All History`
- `This Month`
- `Last Month`
- `Last 3 Months`
- `This Year`

A CrewAssignment qualifies if its actual P4 vessel service period overlaps the selected calendar interval.

## Permissions and tenancy

- `reports.crew_movement_history.view`
- `reports.crew_movement_history.export`

Every query is scoped to `current_company_id`. Soft-deleted/voided assignments are not automatically exposed via `withTrashed()`. Linked assignment previous/next/first-phase/current-phase/vessel/rank/client data never bypass company boundaries. `EmployeeVisibilityScope` is strictly applied across table rows, summary counts, search, filters, and export.

## Export

Export provides both operator-friendly summaries and deep movement details:

- **XLSX Workbook (Multi-sheet)**:
  - **Sheet 1: `CREW HISTORY`** — clean, operator-friendly summary matching the web table (Employee No, Employee Name, Rank, Vessel, Client, Arrival Date, Join Vessel Date, Sign-Off / Disembarkation Date, Return Home Date, Vessel Days, Assignment Status, Assignment No, Assignment Source, Remarks).
  - **Sheet 2: `MOVEMENT DETAILS`** — rich OMS movement history including planned vs actual dates, tour of duty, P0–P6 phase timeline occurrences, training history, accommodation stays, payroll preview days, and corrections.
- **CSV Export**:
  - Exports a clean, flat **Crew History summary** matching the first Excel worksheet without nested structures.

Filenames use `crew-movement-history-YYYY-MM-DD.[xlsx|csv]`.

See also [Crew Movement Corrections](../architecture/crew-movement-corrections.md), [Crew Movement Phases](../architecture/crew-movement-phases.md), and [Crew Payroll Timeline Preparation](../architecture/crew-payroll-timeline-preparation.md).
