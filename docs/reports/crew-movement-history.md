# Crew Movement History

Crew Movement History is the read-only management report for the complete crew mobilisation cycle.

- Current Crew is where operational movements are recorded.
- Crew Planning is where future vessel assignments are planned.
- Crew Movement History reports and exports the resulting assignment and phase history.

## Source of truth

Each row represents one `CrewAssignment`. Planned assignment dates come from the assignment, while actual movement dates come exclusively from ordered `CrewAssignmentPhase` records. Planning rows and `EmployeeSeaService` records are not used to reconstruct actual movement history.

The report excludes soft-deleted phases through the standard `phases` relationship. It does not create, update, or delete operational data.

## Date mapping

| Report value | Source |
|---|---|
| Planned Travel In | First P1 `planned_start_at`, when recorded |
| Planned Join | Assignment `planned_join_at` |
| Planned Sign-Off | Assignment `planned_signoff_at` |
| Planned Travel Home | Assignment `planned_travel_at` |
| Arrival Date | P1 `actual_end_at` |
| Actual Join | First P4 `actual_start_at` |
| Actual Disembarkation | Completed P4 `actual_end_at` |
| Assignment Started | Assignment `started_at` |
| Assignment Closed | Assignment `closed_at` |

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

The report supports identity, assignment status, current phase, vessel, rank, client, visa type, source, attention, and planned/actual date filters. Pagination and exports preserve all filters. Summary counts use the active company and the active filter set.

## Permissions

- `reports.crew_movement_history.view`
- `reports.crew_movement_history.export`

Both report routes enforce their permission independently. Company scoping is always applied by the report query.

## Export

Excel and CSV exports contain one row per assignment. Repeated phase periods and training details use semicolon-separated plain text. Filenames use `crew-movement-history-YYYY-MM-DD`.
