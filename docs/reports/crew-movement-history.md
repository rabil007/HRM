# Crew Movement History

Crew Movement History is the read-only management report for the complete crew mobilisation cycle.

- Crew Assignments is where operational movements are recorded.
- Crew Planning is where future vessel assignments are planned.
- Crew Movement History reports and exports the resulting assignment and phase history.

## Source of truth

Each row represents one `CrewAssignment`. Planned assignment dates come from the assignment, while actual movement dates come exclusively from ordered `CrewAssignmentPhase` records. Accommodation history comes from `CrewAccommodationStay`. Planning rows and `EmployeeSeaService` records are not used to reconstruct actual movement history when assignment/phase records already exist.

The report excludes soft-deleted phases through the standard `phases` relationship. It does not create, update, or delete operational data. Form-only temporary inputs (`no_hotel_accommodation`, `planned_signoff_choice`, `sync_training_to_employee_training`, travel-home `completion_intent`) are never stored as report columns — only the resulting authoritative domain state is shown.

## Modern and legacy phases

The normal report timeline shows only modern product-facing phases:

- P0 Pre-Mobilisation
- P2A Join Standby
- P2B Training
- P4 On Vessel
- P5 Demobilisation Standby
- P6 Home / Redeployment

Legacy phases P1 Travel In and P3 Ready to Join are not rendered as permanent empty placeholders. When an assignment actually recorded legacy movement, the UI shows a separate **Legacy recorded phases** section (and optional export columns) containing only the recorded P1/P3 periods.

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

Vessel transfer and redeployment create linked assignments (`previous_assignment_id`, `source`). The report keeps **one row per CrewAssignment** and exposes previous/next summaries (assignment no, vessel/rank/client, status, timestamps) with links to assignment show pages. Cross-company linked records are rejected.

## Corrections

Approved corrections appear as metadata (`has_corrections`, `correction_count`, `last_corrected_at`). Pending proposals never change official report dates. Official values already reflect approved corrections.

## Needs Attention

Filter, summary card count, row badge, and expanded warning list all use authoritative `CrewMovementAttentionQuery` (including Tour of Duty rules). Active P4 is **not** flagged merely for being active longer than 14 days.

## Payroll calendar-day preview

Uses `CrewMovementHistoryPayrollDays` / `CrewPhasePayCategoryResolver` for Sign-On Standby, On Vessel, Sign-Off Standby, and total. This is a movement/calendar-day preview only — final payroll also depends on contracts and payroll-period eligibility.

## Filters and search

Supports identity, status, current phase, vessel, rank, client, source, needs attention, planned arrival/join/sign-off ranges, actual arrival/join/disembarkation ranges, assignment started/closed ranges, hotel, accommodation status, stay type, tour status, and correction flags.

Search may match assignment no, employee no/name, vessel, client, rank, previous/next assignment no, hotel, room type, and training provider/course — always company-scoped with `EmployeeVisibilityScope`.

## Permissions and tenancy

- `reports.crew_movement_history.view`
- `reports.crew_movement_history.export`

Every query is scoped to `current_company_id`. Soft-deleted/voided assignments are not automatically exposed via `withTrashed()`.

## Export

Excel/CSV: one row per assignment. Repeated phases, training, accommodation, and linked assignments use semicolon-separated plain text. Actual timestamps export with time. Filenames use `crew-movement-history-YYYY-MM-DD`. Legacy columns append only when the filtered set contains P1/P3 movement.

See also [Crew Movement Corrections](../architecture/crew-movement-corrections.md), [Crew Movement Phases](../architecture/crew-movement-phases.md), and [Crew Payroll Timeline Preparation](../architecture/crew-payroll-timeline-preparation.md).
