# Crew Movement History

Crew Movement History is the read-only management report for the complete crew mobilisation cycle.

- Crew Assignments is where operational movements are recorded.
- Crew Planning is where future vessel assignments are planned.
- Crew Movement History reports and exports the resulting assignment and phase history.

## Source of truth

Each row represents one `CrewAssignment`. Planned assignment dates come from the assignment, while actual movement dates come exclusively from ordered `CrewAssignmentPhase` records. Accommodation history comes from `CrewAccommodationStay`. Planning rows and `EmployeeSeaService` records are not used to reconstruct actual movement history when assignment/phase records already exist.

The report excludes soft-deleted phases through the standard `phases` relationship. It does not create, update, or delete operational data. Form-only temporary inputs (`no_hotel_accommodation`, `planned_signoff_choice`, `sync_training_to_employee_training`, travel-home `completion_intent`) are never stored as report columns — only the resulting authoritative domain state is shown.

## Modern and legacy phases

The normal report timeline (**Complete phase timeline**) shows the current six-phase lifecycle:

- P0 Pre-Arrival
- P1A Pre-Joining Standby
- P1B Training
- P2 On Vessel
- P3 Demobilisation Standby
- P4 Home / Redeployment

Retired Travel In and Ready to Join phases were remapped into this lifecycle. Historical assignment rows therefore appear as P0–P4, not as a parallel P1/P3 timeline. Exclusive leftover Travel In, if still present, is excluded from payable service-day categories and does not invent Pre-Joining Standby. When an assignment still has leftover historical Travel In / Ready to Join rows, the UI shows a separate **Legacy recorded phases** section containing only those recorded periods. The presenter still exposes the full chronological `phase_timeline` plus derived `modern_phase_timeline` / `legacy_phase_timeline` without duplicating queries. Do not display retired P1/P3 explanations as if they are part of the current workflow.

The expanded UI still has a **Legacy recorded phases** section for any remaining Travel In / Ready to Join periods (sequence, planned/actual timestamps, status, days, and remarks). After the six-phase remap the presenter currently returns `has_legacy_phases: false` and an empty `legacy_phase_timeline`; `travel_in` / `ready_to_join` summaries stay empty. Compatibility fields remain so older UI contracts do not break.

Current Phase filters use the six-phase list (`p0`, `p1a`, `p1b`, `p2`, `p3`, `p4`). A bookmarked `current_phase=p3` now means Demobilisation Standby, not Ready to Join. Retired `current_phase=p1` is not a current phase code.

## Assignment master

Expanded detail includes assignment number and record ID, employee identity, rank, client/project, vessel, status, current phase, source label, remarks, started/closed timestamps, created/updated timestamps, and company timezone.

## Date mapping and timezone

| Report value | Source |
|---|---|
| Planned Arrival | Assignment `planned_arrival_at` |
| Planned Join | Assignment `planned_join_at` |
| Planned Sign-Off | Assignment `planned_signoff_at` (+ `planned_signoff_source` / override reason) |
| Planned Travel Home | Assignment `planned_travel_at` |
| Actual Arrival | First P1A Pre-Joining Standby `actual_start_at` (`CrewArrivalResolver`) |
| Actual Join | First P2 On Vessel `actual_start_at` |
| Actual Disembarkation | Completed P2 `actual_end_at` |
| Actual Return Home | First P4 Home / Redeployment `actual_start_at` when a completed P3 precedes it |
| Assignment Started / Closed | Assignment `started_at` / `closed_at` |

### Actual Arrival precedence

`CrewArrivalResolver` is authoritative for UI display, export, and Actual Arrival From/To filters:

1. Use the **first** P1A / Pre-Joining Standby occurrence by `sequence` with `actual_start_at`.
2. Remapped historical P2A arrivals are already stored as P1A after the six-phase lifecycle remap.
3. Later repeated P1A occurrences never override that value.
4. There is **no** Travel In fallback. A gap between arrival and joining, or an assignment that starts on vessel, does not invent an arrival date.

Filters apply `from`/`to` against that same resolved P1A occurrence (not against any matching phase via loose `whereHas`).

Planned values remain date-oriented. **Actual operational events preserve company-local date and time** (`Y-m-d H:i:s` wall clock in the company timezone). Expanded UI and exports use those timestamps; compact table cells may stay concise.

Provenance labels continue to use `CrewDateProvenance`. Planned Sign-Off is never presented as Actual Disembarkation. Browser/device timezone is never used.

## Day counting

Crew Movement History keeps several counts. They are not interchangeable.

| Count | Meaning |
|---|---|
| Vessel Service Days | Allocated payable On Vessel (P2) calendar dates. Each employee/date is owned by one category. 4 October through 7 October is 4 vessel service days, including the disembarkation date. |
| Pre-Joining Standby (Days) | Allocated sign-on standby dates after payable-date ownership. **Includes P1A Pre-Joining Standby and P1B Training.** Training is the same payroll Sign-On Standby category, not a separate report bucket. |
| Demobilisation Standby (Days) | Allocated sign-off standby dates (P3 Demobilisation Standby only) after payable-date ownership. Vessel and Home / Redeployment dates are never included. |
| Total Standby | Pre-Joining Standby + Demobilisation Standby. Missing phases count as zero; both phases are not required. |
| Phase service days | Allocated dates for payable phases (P1A, P1B, P2, P3). P0 and P4 show the actual calendar span instead and add no payable service days. |
| Movement span | Inclusive company-local dates touched by the recorded phase window. A handoff date can sit in this span and still belong to another category. |
| Elapsed assignment days / elapsed days onboard | Whole calendar days from the start date to the end date, without adding the end date. Same-day elapsed time is 0. Tour of Duty uses this elapsed count and is unchanged. |
| Payroll calendar-day preview | The same payable-date ownership, without the operational “stop at today” clamp. Contract eligibility is still applied only in payroll. |
| Hotel days | Inclusive stay dates (`check-out − check-in + 1`). Never reduced because the crew member joined or left the vessel. |

Payable-date priority, shared with automatic Crew Timesheet preparation:

1. On Vessel / Onsite (P2)
2. Demobilisation / Sign-Off Standby (P3)
3. Pre-Joining / Sign-On Standby, including Training (P1A, P1B)
4. Excluded phases (P0, P4)

A pre-joining window of 1–5 October whose vessel join is 5 October is **4 pre-joining standby days** (1–4 October). The movement span is still 1–5 October, and 5 October is vessel service. A demobilisation window of 10–14 October whose disembarkation is 10 October is **4 demobilisation standby days** (11–14 October). 10 October stays on the vessel. 14 October stays demobilisation standby because Home / Redeployment does not outrank it. If that home phase also covers 14–17 October, its phase duration stays 4 calendar days while payroll still counts 14 October as sign-off standby. The recorded timestamps are not changed to make the count match. A recorded half-day standby counts as 1 day for this report.

Summary Total Standby, Pre-Joining / Demobilisation breakdowns, expanded phase service days, and the XLSX/CSV service-day columns use this allocation for the same assignment and cutoff. A same-day vessel transfer or redeployment is one payable onsite day for the employee: the linked assignment that loses the tie does not also count it. Planned dates never become payable service. Gaps between arrival and joining never invent standby. A future actual start is labeled `Future actual` and adds no operational service days. That warning describes the assignment being viewed; a future movement on a linked assignment does not mark a completed assignment as future actual. An active phase includes company-local today and no later date. Soft-deleted assignments and phases do not compete for payable dates. A cancelled assignment that still has a completed payable phase remains eligible. Export rows load linked phases once per chunk rather than once per row.

## Repeated phases and training

Phase occurrences are ordered by `sequence`. Repeated P1A/P1B periods remain separate in the timeline and in exports. Each P1B training occurrence exposes provider, course, planned/actual windows, remarks, and whether an `EmployeeTraining` record is linked via `CrewAssignmentPhase::employeeTraining()`.

Allocated P1B Training days are included in **Pre-Joining Standby (Days)** and therefore in **Total Standby**. That matches payroll Sign-On Standby (`CrewPhasePayCategoryResolver`) and is not changed by this report.

## Accommodation history

Dedicated Accommodation History uses `CrewAccommodationService::assignmentAccommodationSummary()` / `crew_accommodation_stays`. Show stay type, accommodation status, hotel, room type, check-in/out dates, open/completed, stay days, and starting phase when present.

- Hotel is shown only when a stay exists with `accommodation_status = hotel`.
- Explicit `no_accommodation` stays display as **No Accommodation**.
- Phase codes alone never invent hotel occupancy.
- Multiple stays are serialized; none are silently dropped.
- **Hotel days** use the inclusive hotel formula (`check-out − check-in + 1`). Same-day and half-day stays are 1 hotel day. These are not vessel or standby service days and are not payroll days.

## Tour of Duty & sign-off

Tour progress reuses `CrewTourProgress` (days onboard, remaining days, status). Report fields include tour of duty days, planned sign-off source/label, and manual override reason. Completed assignments freeze progress at disembarkation and avoid misleading active urgency wording when status is null.

## Linked assignment journey

Vessel transfer and redeployment create linked assignments (`previous_assignment_id`, `source`). The report keeps **one row per CrewAssignment** and exposes previous/next summaries with links to assignment show pages. Cross-company linked records are rejected.

Starting checkpoint and current phase are separate concepts on each linked summary:

- **Starting checkpoint** — first persisted `CrewAssignmentPhase` ordered by `sequence` on the linked assignment (for example a Redeployment destination that began at P1A).
- **Current phase** — the assignment’s `currentPhase` (which may later be P2 even though the destination originally started at P1A).

The journey **Relationship** (Vessel Transfer / Redeployment) is shown at the Linked Assignment Journey section level. Each linked card’s **Assignment source** is that assignment’s own creation `source` (for example Manual on a previous assignment) and must not be labeled as the movement relationship.

Export includes Starting Checkpoint for the current row and serializes next-assignment starting/current checkpoints when present.

## Corrections

Approved corrections appear as metadata (`has_corrections`, `correction_count`, `last_corrected_at`). Pending proposals never change official report dates. Official values already reflect approved corrections.

## Needs Attention

Filter, summary card count, row badge, and expanded warning list all use authoritative `CrewMovementAttentionQuery` (including Tour of Duty rules). Active P2 On Vessel is **not** flagged merely for being active longer than 14 days.

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
  - *How many pre-joining standby days?* (Pre-Joining Standby (Days) — allocated sign-on standby dates after payable-date ownership. Includes recorded Pre-Joining Standby (P1A) and Training (P1B). Missing phase shows `Not recorded` when no service was recorded, otherwise `0 days`. Gaps between arrival and joining never invent standby.)
  - *When did they join the vessel?* (Joined Vessel — actual P2 join)
  - *When did they sign off / disembark?* (Sign-Off / Disembarked — actual P2 end, or `Ongoing` if active)
  - *How many demobilisation standby days?* (Demobilisation Standby (Days) — allocated Demobilisation Standby (P3) dates after payable-date ownership. Missing phase shows `Not recorded` / `0 days`. Vessel and home/redeployment dates are never counted as demobilisation standby.)
  - *When did they return home?* (Returned Home — actual P4 start, or `Redeployed` if transferred directly without returning home)
  - *How many total standby days?* (Total Standby — Pre-Joining Standby + Demobilisation Standby. Either phase may be absent; missing phases count as zero. Same-day and half-day recorded standby counts as 1 day.)
  - *How many vessel service days?* (Vessel Service Days — allocated P2 dates. 4 October through 7 October is 4 days, including the disembarkation date. This is not elapsed time and not the tour-of-duty “elapsed days onboard” count.)
  - *What is the current status?* (Assignment status badge, with `On Vessel` for active P2)
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
- `planned_signoff_at` must never appear as actual sign-off. If P2 is still active, sign-off is displayed as `Ongoing`.
- If a crew member is redeployed or transferred directly to another vessel without returning home, the outcome is clearly labeled `Redeployed` (with date), preserving true operational semantics.

## Vessel Service Period filter

In addition to granular date range filters, a primary quick filter for **Vessel Service Period** allows filtering assignments by actual P2 On Vessel period overlap:
- `All History`
- `This Month`
- `Last Month`
- `Last 3 Months`
- `This Year`

A CrewAssignment qualifies if its actual P2 vessel service period overlaps the selected calendar interval.

## Permissions and tenancy

- `reports.crew_movement_history.view`
- `reports.crew_movement_history.export`

Every query is scoped to `current_company_id`. Soft-deleted/voided assignments are not automatically exposed via `withTrashed()`. Linked assignment previous/next/first-phase/current-phase/vessel/rank/client data never bypass company boundaries. `EmployeeVisibilityScope` is strictly applied across table rows, summary counts, search, filters, and export.

## Export

Export provides both operator-friendly summaries and deep movement details:

- **XLSX Workbook (Multi-sheet)**:
  - **Sheet 1: `CREW HISTORY`** — clean, operator-friendly summary matching the web table (Employee No, Employee Name, Rank, Vessel, Client, Arrival Date, Pre-Joining Standby (Days), Join Vessel Date, Sign-Off / Disembarkation Date, Demobilisation Standby (Days), Return Home Date, Total Standby, Vessel Service Days, Assignment Status, Assignment No, Assignment Source, Remarks).
  - **Sheet 2: `MOVEMENT DETAILS`** — rich OMS movement history including planned vs actual dates, tour of duty, P0–P4 phase timeline occurrences (plus a legacy section only when historical Travel In / Ready to Join rows exist), training history, accommodation stays, pre-joining / demobilisation / total standby service days, payroll preview days, and corrections.
- **CSV Export**:
  - Exports a clean, flat **Crew History summary** matching the first Excel worksheet without nested structures.

Filenames use `crew-movement-history-YYYY-MM-DD.[xlsx|csv]`.

See also [Crew Movement Corrections](../architecture/crew-movement-corrections.md), [Crew Movement Phases](../architecture/crew-movement-phases.md), and [Crew Payroll Timeline Preparation](../architecture/crew-payroll-timeline-preparation.md).
