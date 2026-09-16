# Crew Movement History

Crew Movement History is the read-only management report for the complete crew mobilisation cycle.

- Crew Assignments is where operational movements are recorded.
- Crew Planning is where future vessel assignments are planned.
- Crew Movement History reports and exports the resulting assignment and phase history.

## Source of truth

Each row represents one `CrewAssignment`. Planned assignment dates come from the assignment, while actual movement dates come exclusively from ordered `CrewAssignmentPhase` records. Planning rows and `EmployeeSeaService` records are not used to reconstruct actual movement history.

The report excludes soft-deleted phases through the standard `phases` relationship. It does not create, update, or delete operational data.

## Modern and legacy phases

The normal report timeline shows only modern product-facing phases:

- P0 Pre-Mobilisation
- P2A Join Standby
- P2B Training
- P4 On Vessel
- P5 Demobilisation Standby
- P6 Home / Redeployment

Legacy phases P1 Travel In and P3 Ready to Join are not rendered as permanent empty placeholders. When an assignment actually recorded legacy movement, the UI shows a separate **Legacy recorded phases** section containing only the recorded P1/P3 periods.

Current Phase filters offer the same modern phase list. Bookmarked legacy `current_phase=p1` or `current_phase=p3` query values remain supported by the backend filter when present.

## Date mapping

| Report value | Source |
|---|---|
| Planned Arrival | Assignment `planned_arrival_at` |
| Planned Join | Assignment `planned_join_at` |
| Planned Sign-Off | Assignment `planned_signoff_at` |
| Planned Travel Home | Assignment `planned_travel_at` |
| Actual Arrival | `CrewArrivalResolver` — first P2A `actual_start_at`, with legacy fallback to completed P1 `actual_end_at` |
| Actual Join | First P4 `actual_start_at` |
| Actual Disembarkation | Completed P4 `actual_end_at` |
| Assignment Started | Assignment `started_at` |
| Assignment Closed | Assignment `closed_at` |

Planned Travel In is no longer part of the normal modern planned-movement presentation. When legacy P1 data exists, its planned start may appear in the legacy section or export only.

Planned Sign-Off is never presented as Actual Disembarkation.

## Repeated phases

Phase occurrences are ordered by `sequence`. Repeated P2A Join Standby and P2B Training periods remain visible in one assignment row and are exported as semicolon-separated periods. Training provider and course details are preserved per occurrence. Other repeated phases are summarized with the same period structure.

## Duration rule

Durations use elapsed company-local calendar days:

- Completed phase: `actual_start_at` to `actual_end_at`.
- Active phase: `actual_start_at` to company-local today.
- Planned-only phase: no actual duration.

The start day is day zero. A phase started today displays `Started today`; the following day displays `1 day`. All values are whole numbers.

## Filters and summary

The report supports identity, assignment status, current phase, vessel, rank, client, source, attention, planned/actual date filters, and correction filters (`has_approved_corrections`, `has_pending_corrections`). Pagination and exports preserve all filters. Summary counts use the active company and the active filter set.

Approved corrections add report metadata only (`has_corrections`, `correction_count`, `last_corrected_at`). Pending proposals never change official dates in the report.

## Permissions

- `reports.crew_movement_history.view`
- `reports.crew_movement_history.export`

Both report routes enforce their permission independently. Company scoping is always applied by the report query.

## Export

Excel and CSV exports contain one row per assignment. Repeated phase periods and training details use semicolon-separated plain text. Correction metadata columns are included for approved corrections. Filenames use `crew-movement-history-YYYY-MM-DD`.

Modern exports omit empty legacy columns such as Planned Travel In, P1 From/To/Days, and Ready From/To/Days. When the filtered export result set contains actual legacy P1 or P3 movement, additional **Legacy …** columns are appended after Actual Arrival so historical data is preserved without misleading empty columns on modern-only exports.

See also [Crew Movement Corrections](../architecture/crew-movement-corrections.md).
