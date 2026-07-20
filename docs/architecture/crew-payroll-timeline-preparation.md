# Crew Payroll Timeline Preparation

This document describes the **Phase 1A data foundation** for the future crew payroll timeline preparation and approval workflow.

## Intended future flow

```text
Crew Operations actual phases
    → monthly timeline preparation
    → crewing verification
    → crewing approval
    → apply approved operational days to CrewTimesheet
    → payroll financial inputs
    → payroll generation
```

Phase 1A introduces only the schema and domain models required by later phases. No preparation service, approval UI, routes, permissions, or payroll blocking exist yet.

## Source-of-truth rules

- `CrewAssignment` and `CrewAssignmentPhase` remain the operational source of truth for movements.
- Planned dates must never become actual payroll dates.
- Payroll must not wait for an assignment to finish before monthly preparation can exist.
- Phase 1 initially supports automatic movement mapping for **daily** crew only.
- Monthly crew payroll continues to treat legacy `standby_days` as leave/unpaid days. That behavior is unchanged in Phase 1A.

## Phase 1A schema

### Extended `crew_timesheets`

Legacy standby/onsite fields remain in place:

- `standby_from`, `standby_to`, `standby_days`
- `onsite_from`, `onsite_to`, `onsite_days`

Additive payable standby fields:

- `sign_on_standby_from`, `sign_on_standby_to`, `sign_on_standby_days`
- `sign_off_standby_from`, `sign_off_standby_to`, `sign_off_standby_days`

Operational source metadata:

- `source` — `manual`, `import`, `crew_operations`
- `crew_timesheet_preparation_id`
- `operational_approved_by`, `operational_approved_at`
- `movement_source_hash`

### `crew_timesheet_preparations`

Versioned, company-scoped preparation records for a payroll period.

Statuses:

- `draft`
- `submitted`
- `returned`
- `approved`
- `applied`
- `superseded`

Each `(company_id, payroll_period_id, version)` tuple is unique. Older versions are preserved; later phases must supersede rather than overwrite history.

### `crew_timesheet_preparation_lines`

Line-level proposed operational pay categories for one preparation version.

Pay categories:

- `sign_on_standby`
- `onsite`
- `sign_off_standby`
- `excluded`

Lines may repeat for the same employee, assignment, or phase when multiple actual ranges exist in one period.

## Daily legacy backfill

Existing daily crew timesheets were backfilled additively:

- `standby_from` → `sign_on_standby_from`
- `standby_to` → `sign_on_standby_to`
- `standby_days` → `sign_on_standby_days`

Monthly crew timesheets were **not** backfilled into the new payable standby fields because monthly payroll currently uses legacy `standby_days` differently.

## Key implementation files

| Layer | Path |
|---|---|
| Enums | `app/Enums/CrewTimesheetSource.php`, `CrewTimesheetPreparationStatus.php`, `CrewTimesheetPayCategory.php` |
| Models | `app/Models/CrewTimesheetPreparation.php`, `CrewTimesheetPreparationLine.php` |
| Extended model | `app/Models/CrewTimesheet.php` |
| Migrations | `database/migrations/2026_07_20_120000_create_crew_timesheet_preparations_table.php`, `2026_07_20_120001_create_crew_timesheet_preparation_lines_table.php`, `2026_07_20_120002_add_timeline_preparation_fields_to_crew_timesheets_table.php` |
| Tests | `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1ATest.php` |

## Out of scope for Phase 1A

- Timeline preparation service
- Phase-to-pay-category calculation
- Daily date allocation
- Source hash calculation
- Crewing review UI
- Submit/return/approve/apply actions
- New routes, controllers, or permissions
- Payroll generation blocking
- Monthly crew movement synchronization
- Direct Crew Operations → `CrewTimesheet` synchronization

See also [crew-movement-phases.md](./crew-movement-phases.md) and [payroll.md](../payroll.md).
