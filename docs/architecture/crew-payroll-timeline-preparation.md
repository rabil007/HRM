# Crew Payroll Timeline Preparation

This document describes the crew payroll timeline preparation architecture.

## Intended flow

```text
Payroll creates Crew Pay Period manually
    → Prepare from Crew Operations
    → draft versioned preparation + lines/issues
    → crewing verification (Phase 1C)
    → crewing approval (Phase 1C)
    → apply approved operational days to CrewTimesheet (Phase 1D)
    → payroll financial inputs
    → payroll generation (Phase 1E safeguards)
```

## Source-of-truth rules

- `CrewAssignment` and `CrewAssignmentPhase` remain the operational source of truth for movements.
- Planned dates must never become actual payroll dates.
- Only `actual_start_at` and `actual_end_at` are used.
- Payroll must not wait for an assignment to finish before monthly preparation can exist.
- Automatic preparation currently supports **daily** crew only.
- Monthly crew payroll continues to treat legacy `standby_days` as leave/unpaid days.

## Phase mapping

| Phase | Pay category |
|-------|--------------|
| P0 Pre-Mobilisation | Excluded |
| P1 Travel In | Excluded (informational issue) |
| P2A Join Standby | Sign-On Standby |
| P2B Training | Sign-On Standby |
| P3 Ready to Join | Sign-On Standby |
| P4 On Vessel | Onsite |
| P5 Demobilisation Standby | Sign-Off Standby |
| P6 Home / Redeployment | Excluded |

Day priority when categories overlap:

1. Onsite
2. Sign-Off Standby
3. Sign-On Standby
4. Excluded

Overlaps still create a blocking `overlapping_phases` warning.

## Active phases

When `actual_end_at` is null on an active phase, the effective end is the earliest of:

- payroll period end
- selected cutoff date
- company-local current date

Future payable days are never generated.

## Phase 1A schema

### Extended `crew_timesheets`

Legacy standby/onsite fields remain. Additive payable standby and source metadata fields support later apply steps.

### `crew_timesheet_preparations`

Versioned, company-scoped preparation records. Unique `(company_id, payroll_period_id, version)`. Older versions are preserved.

### `crew_timesheet_preparation_lines`

Line-level proposed operational pay categories and warning rows (`warning_code`).

## Phase 1B preparation engine

Implemented Support classes under `app/Support/Payroll/CrewTimeline/`:

| Class | Role |
|---|---|
| `PrepareCrewTimesheetTimeline` | Orchestrates draft preparation creation |
| `CrewTimelinePhaseQuery` | Loads overlapping actual phases and effective end |
| `CrewTimelineDayAllocator` | Allocates one category per calendar day |
| `CrewPhasePayCategoryResolver` | Maps phase → pay category and priority |
| `CrewTimelineSourceHasher` | SHA-256 source fingerprint |
| `CrewTimelineIssueDetector` | Warning/issue detection |
| `CrewTimelineRangeBuilder` | Contiguous ranges per phase/category |

### HTTP

- `POST /payroll/{payrollPeriod}/crew-timeline/prepare`
- Route name: `payroll.crew-timeline.prepare`
- Permission: `payroll.crew_timesheets.prepare`
- Controller: `PrepareCrewTimesheetTimelineController`

Draft crew periods show a **Prepare from Crew Operations** header action for authorized users.

### Behavior

- Creates a new draft preparation with the next locked version number
- Does not overwrite older versions
- Does not approve, apply to `crew_timesheets`, or generate payroll
- Monthly crew and employees without an active crew contract are skipped with warnings
- Pending movement corrections produce warnings

## Warning codes

Stored in `crew_timesheet_preparation_lines.warning_code` via `CrewTimelineWarningCode`.

Blocking (for future submit): `missing_actual_start`, `missing_actual_end`, `overlapping_phases`, `pending_movement_correction`, `no_active_crew_contract`, `cross_company_reference`, `invalid_phase_range`

Informational: `timeline_gap`, `monthly_contract_not_supported`, `future_actual_date`, `travel_in_excluded`

## Tests

- Phase 1A: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1ATest.php`
- Phase 1B: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1BTest.php`

## Out of scope until later phases

- Submit / return / approve workflow (1C)
- Apply to `crew_timesheets` (1D)
- Daily calculator/UI/generation safeguards (1E)
- Monthly crew movement integration
- Travel payment configuration
- Vessel transfer / redeployment

See also [crew-movement-phases.md](./crew-movement-phases.md) and [payroll.md](../payroll.md).
