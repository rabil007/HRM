# Crew Payroll Timeline Preparation

This document describes the crew payroll timeline preparation architecture.

## Intended flow

```text
Payroll creates Crew Pay Period manually
    → Prepare from Crew Operations
    → draft versioned preparation + lines/issues
    → crewing review / submit (Phase 1C)
    → crewing approve or return (Phase 1C)
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
- Monthly crew payroll uses the explicit `unpaid_leave_days` field for leave/unpaid days.

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

The priority winner is assigned the day, so each calendar day always resolves to exactly one pay category.

### Handoffs vs genuine overlaps

Crew movement phases are treated as half-open timestamp intervals `[actual_start_at, actual_end_at)`. `CrewMovementService` records a single `occurred_at` per action, closing the current phase and opening the next at that same instant, so `previous.actual_end_at == next.actual_start_at` is a **valid exact handoff, not an overlap**. Because payroll converts timestamps to inclusive calendar-day ranges, both phases legitimately claim the shared transition date; priority alone decides the winner and no warning is raised.

A blocking `overlapping_phases` warning is raised **only when two claiming phases genuinely overlap in time** — a positive-duration intersection where `left.start < right.end AND right.start < left.end`. This decision is made by `CrewPhaseIntervalOverlapDetector` on absolute source timestamps (so it is timezone-correct and never compares date strings); `CrewTimelineDayAllocator` calls it per multi-claim day. Exact-boundary equality, zero-duration phases, and same-day disjoint intervals (e.g. `08:00–10:00` then `14:00–18:00`) are therefore never flagged. Only genuine overlaps stay blocking (they prevent submission and approval).

No separate phase start/end inputs are required for normal movement actions; explicit actual-date changes continue through the Crew Movement Correction workflow.

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

Additive return audit fields:

- `returned_by`
- `returned_at`

Do not overload `approved_by` for return decisions.

### `crew_timesheet_preparation_lines`

Line-level proposed operational pay categories and warning rows (`warning_code`). Warning-only rows use `days = 0` and must not contribute to payable totals.

## Phase 1B preparation engine

Implemented Support classes under `app/Support/Payroll/CrewTimeline/`:

| Class | Role |
|---|---|
| `PrepareCrewTimesheetTimeline` | Orchestrates draft preparation creation |
| `CrewTimelinePhaseQuery` | Loads overlapping actual phases and effective end |
| `CrewTimelineDayAllocator` | Allocates one category per calendar day |
| `CrewPhaseIntervalOverlapDetector` | Positive-duration timestamp overlap check (handoff vs genuine overlap) |
| `CrewPhasePayCategoryResolver` | Maps phase → pay category and priority |
| `CrewTimelineSourceHasher` | SHA-256 source fingerprint |
| `CrewTimelineIssueDetector` | Warning/issue detection |
| `CrewTimelineRangeBuilder` | Contiguous ranges per phase/category |

### HTTP

- `POST /payroll/{payrollPeriod}/crew-timeline/prepare`
- Route name: `payroll.crew-timeline.prepare`
- Permission: `payroll.crew_timesheets.prepare`
- Controller: `PrepareCrewTimesheetTimelineController`

Draft crew periods show a **Prepare from Crew Operations** header action for authorized users. Successful prepare redirects to the Phase 1C review page.

### Behavior

- Creates a new draft preparation with the next locked version number
- Does not overwrite older versions
- Does not approve, apply to `crew_timesheets`, or generate payroll
- Monthly crew and employees without an active crew contract are skipped with warnings
- Pending movement corrections produce warnings

## Phase 1C review, submission, return, and approval

### Status transitions

| From | To | Notes |
|------|----|-------|
| Draft | Submitted | Latest version only |
| Submitted | Approved | Maker-checker enforced |
| Submitted | Returned | Return notes required |
| Approved | Superseded | Only when a newer version is approved |
| Returned | (history) | Do not change Returned back to Draft |

`Applied` is set by Phase 1D.

Correction path after return or stale data:

```text
Correct Crew Operations movement data
    → prepare a new version
    → review and submit the new version
```

### Workflow rules

1. Only crew payroll periods are supported.
2. Payroll period must remain Draft.
3. Only the latest preparation version may be submitted.
4. Only a Submitted preparation may be approved or returned.
5. Only one Submitted preparation may exist for a company and payroll period.
6. Only one active Approved preparation may exist for a company and payroll period.
7. Older preparation versions remain preserved.
8. Approved preparation lines are immutable.
9. Generated preparation lines cannot be manually edited.
10. Planned Crew Operations dates must never be used.

### Maker-checker

The approving user must not be `prepared_by` or `submitted_by`.

The same user may prepare and submit. A different authorized user must approve.

No approval override permission in this phase.

### Source freshness

`CrewTimelineFreshnessChecker` recalculates the source hash from:

- the preparation’s payroll period
- the preparation’s cutoff date
- current overlapping actual phases via `CrewTimelinePhaseQuery`
- `CrewTimelineSourceHasher`

A preparation is stale when the recalculated hash differs from `source_hash`.

Freshness is checked on the review page, before submission, and before approval.

Stale preparations cannot be submitted or approved. Message:

> The Crew Operations timeline changed after this preparation was created. Prepare a new version before continuing.

Do not update the old preparation’s `source_hash`.

### Blocking warnings

Submission and approval are blocked when any line has a blocking `CrewTimelineWarningCode`.

Blocking: `missing_actual_start`, `missing_actual_end`, `overlapping_phases`, `pending_movement_correction`, `no_active_crew_contract`, `cross_company_reference`, `invalid_phase_range`

Informational (allowed): `timeline_gap`, `monthly_contract_not_supported`, `future_actual_date`, `travel_in_excluded`

### Approval behavior

When approving a Submitted preparation:

1. Lock the payroll period and relevant preparations.
2. Confirm the payroll period is still Draft.
3. Confirm source data is fresh.
4. Confirm no blocking warnings exist.
5. Confirm approver differs from `prepared_by` and `submitted_by`.
6. Mark any previous Approved preparation for the same company/period as Superseded.
7. Do not supersede an Applied preparation.
8. If an Applied preparation already exists, reject approval.
9. Set `status = approved`, `approved_by`, `approved_at`, optional `decision_notes`.

### Return behavior

- `decision_notes` required
- `status = returned`
- store `returned_by` and `returned_at`

### Submission behavior

- confirm latest version, freshness, no blocking warnings
- ensure no other Submitted preparation exists
- set `status = submitted`, `submitted_by`, `submitted_at`

### Support classes

| Class | Role |
|---|---|
| `Actions/SubmitCrewTimesheetPreparation` | Draft → Submitted |
| `Actions/ReturnCrewTimesheetPreparation` | Submitted → Returned |
| `Actions/ApproveCrewTimesheetPreparation` | Submitted → Approved (+ supersede) |
| `CrewTimelineFreshnessChecker` | Source hash comparison |
| `CrewTimesheetPreparationWorkflowGuard` | Shared validation |
| `CrewTimesheetPreparationReviewQuery` | Tenant-safe load |
| `CrewTimesheetPreparationReviewResource` | Inertia review payload |
| `CrewTimesheetPreparationSummaryResource` | Payroll show summary |
| `CrewTimelinePagePermissions` | Review page permission flags |

### HTTP

| Method | Path | Route name | Permission |
|--------|------|------------|------------|
| GET | `/payroll/{payrollPeriod}/crew-timeline/{preparation}` | `payroll.crew-timeline.show` | `payroll.crew_timesheets.view` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/submit` | `payroll.crew-timeline.submit` | `payroll.crew_timesheets.submit` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/approve` | `payroll.crew-timeline.approve` | `payroll.crew_timesheets.approve` |
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/return` | `payroll.crew-timeline.return` | `payroll.crew_timesheets.return` |

Inertia page: `resources/js/pages/payroll/crew-timeline/show.tsx`

Feature UI: `resources/js/features/payroll/crew-timeline/`

Dates display as `dd-mm-yyyy`. Backend values remain ISO.

Phase 1C does not write to `crew_timesheets`.

## Phase 1D apply approved timeline to timesheets

### Status transition

| From | To | Notes |
|------|----|-------|
| Approved | Applied | Only transition that may write `crew_timesheets` |

Only one Applied preparation may exist per company and payroll period.

After Applied exists:

- new prepare versions are blocked
- the Applied preparation is read-only
- replacement requires a future payroll correction workflow (not Phase 1D)

### Aggregation

Payable lines only (`sign_on_standby`, `onsite`, `sign_off_standby` with `days > 0`).

Per employee (`company_id` + `employee_id` + `period_id`):

- sum days per category
- earliest `from_date` / latest `to_date` per category
- daily crew contracts only; monthly crew employees are skipped
- excluded / warning-only / zero-day rows do not contribute

### CrewTimesheet fields written

- `sign_on_standby_*`, `onsite_*`, `sign_off_standby_*`
- `source = crew_operations`
- `crew_timesheet_preparation_id`
- `operational_approved_by` / `operational_approved_at` from preparation approval
- `movement_source_hash = preparation.source_hash`

Financial fields are preserved: overtime, additions, deductions, remarks, salary inputs.

### Split operational fields (no legacy compatibility)

On apply, Phase 1D writes only the split operational fields:

- `sign_on_standby_from` / `sign_on_standby_to` / `sign_on_standby_days`
- `onsite_from` / `onsite_to` / `onsite_days`
- `sign_off_standby_from` / `sign_off_standby_to` / `sign_off_standby_days`

The legacy generic standby columns (`standby_from`, `standby_to`, `standby_days`) were intentionally removed before any production payroll data existed. No mirroring, fallback, or compatibility bridge remains. `CrewPayrollCalculator` computes `total_standby_days = sign_on_standby_days + sign_off_standby_days` for every daily source (Manual, Import, and Applied `crew_operations`); Monthly crew uses `unpaid_leave_days`.

## Phase 1E — dual mode UI, generation guard, calculator split

Phase 1E completes the crew dual-mode rollout:

- `payroll_periods.crew_timesheet_mode` (`manual` | `crew_operations`)
- create form timesheet source selector for crew periods
- period show badge, timeline UI only in crew-operations mode, generation blocking banner
- operationally locked daily rows display sign-on/sign-off/onsite read-only while overtime/financial fields stay editable
- import template instructions differ for crew-operations periods (Daily operational columns left blank)
- `CrewOperationsPayrollGenerationGuard` blocks crew-operations generation until an Applied preparation exists
- `CrewPayrollCalculator` uses the same split sign-on/onsite/sign-off structure for all daily sources (Manual, Import, Applied crew-operations); Monthly crew uses `unpaid_leave_days`

Tests:

- `tests/Feature/Payroll/CrewTimesheetModePhase1ETest.php`
- `tests/Unit/Support/Payroll/CrewPayrollCalculatorCrewOperationsTest.php`

### Operational field locking

When `source = crew_operations` and the linked preparation is Applied, `UpsertCrewTimesheet` (manual and import) may update only financial fields. Operational dates/days and source metadata stay locked.

Manual saves set `source = manual` for non-locked timesheets. Import sets `source = import`.

### Freshness for apply

Stale message:

> The Crew Operations timeline changed after this preparation was approved. Prepare and approve a new version before applying it to payroll.

### Support / HTTP

| Class | Role |
|---|---|
| `Actions/ApplyCrewTimesheetPreparation` | Approved → Applied + timesheet upsert |
| `ApplyCrewTimesheetPreparationResult` | Counts, skips, idempotent flag |

| Method | Path | Route name | Permission |
|--------|------|------------|------------|
| POST | `/payroll/{payrollPeriod}/crew-timeline/{preparation}/apply` | `payroll.crew-timeline.apply` | `payroll.crew_timesheets.apply_approved` |

## Warning codes

Stored in `crew_timesheet_preparation_lines.warning_code` via `CrewTimelineWarningCode`.

Blocking: `missing_actual_start`, `missing_actual_end`, `overlapping_phases`, `pending_movement_correction`, `no_active_crew_contract`, `cross_company_reference`, `invalid_phase_range`

Informational: `timeline_gap`, `monthly_contract_not_supported`, `future_actual_date`, `travel_in_excluded`

## Production hardening (post Phase 1E)

Hardening applied before production use. Manual / Excel and Monthly crew behaviour preserved.

- **Contract resolution** — `ResolveCrewContractForPayrollPeriod` resolves the period-applicable crew contract (overlap by effective/end dates) and is used across preparation, issue detection, allocation, application, upsert, import, generation, readiness, and the source hasher.
- **Payable predicate** — `CrewTimeline/PayableCrewPreparationLines` is the single payable-line predicate (sign-on standby / onsite / sign-off standby with days > 0) shared by application, readiness, and generation. Excluded, warning-only, and zero-day lines never require a linked timesheet.
- **Readiness parity** — `CrewOperationsPayrollGenerationGuard::validateReadiness()` is the shared non-mutating validator; the Generate button and backend generation agree and expose `affected_employee_id`.
- **Source freshness** — the source hash now covers the period-applicable contract (id, category, salary structure, effective dates) and pending movement correction state, so contract/salary-structure/correction/actual-movement/phase changes make a preparation stale.
- **Query split & timezone** — `CrewTimelinePhaseQuery::issuePhases()` surfaces phases missing `actual_start_at` (blocking `missing_actual_start`) while `overlappingPhases()` stays actual-only; both use company-timezone boundaries compared in UTC.
- **Empty Applied preparation** — apply is allowed with zero payable Daily employees, marked Applied, idempotent, and does not block generation.
- **Concurrency** — generation and `UpsertCrewTimesheet` lock the period (and rows) and revalidate status/mode/lock state; generation derives mode from the locked model. Import financial writes use explicit-presence handling to preserve stored amounts.
- **History** — `CrewTimesheetPreparation` and `CrewTimesheetPreparationLine` use `SoftDeletes`; creation migrations recover missing columns/indexes idempotently.
- **Excel template** — Daily crew operational cells are locked/protected in crew-operations mode (`From timeline`); Monthly rows keep legacy operational cells; backend validation stays authoritative.

## Tests

- Phase 1A: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1ATest.php`
- Phase 1B: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1BTest.php`
- Phase 1C: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1CTest.php`
- Phase 1D: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1DTest.php`
- Phase 1E: `tests/Feature/Payroll/CrewTimesheetModePhase1ETest.php`
- Hardening: `tests/Feature/Payroll/CrewPayrollHardeningTest.php`, `tests/Unit/Support/Payroll/ResolveCrewContractForPayrollPeriodTest.php`
- Shared fixtures: `tests/Support/crew-timeline-fixtures.php`

## Out of scope until later phases

- Monthly crew movement integration
- Travel payment configuration
- Vessel transfer / redeployment
- Direct editing of generated timeline lines
- Payroll correction workflow for replacing Applied preparations

See also [crew-movement-phases.md](./crew-movement-phases.md) and [payroll.md](../payroll.md).
