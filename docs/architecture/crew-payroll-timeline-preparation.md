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

`Applied` exists for Phase 1D and must not be set in Phase 1C.

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

## Warning codes

Stored in `crew_timesheet_preparation_lines.warning_code` via `CrewTimelineWarningCode`.

Blocking: `missing_actual_start`, `missing_actual_end`, `overlapping_phases`, `pending_movement_correction`, `no_active_crew_contract`, `cross_company_reference`, `invalid_phase_range`

Informational: `timeline_gap`, `monthly_contract_not_supported`, `future_actual_date`, `travel_in_excluded`

## Tests

- Phase 1A: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1ATest.php`
- Phase 1B: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1BTest.php`
- Phase 1C: `tests/Feature/Payroll/CrewTimesheetTimelinePreparationPhase1CTest.php`
- Shared fixtures: `tests/Support/crew-timeline-fixtures.php`

## Out of scope until later phases

- Apply to `crew_timesheets` (1D)
- Daily calculator/UI/generation safeguards (1E)
- Monthly crew movement integration
- Travel payment configuration
- Vessel transfer / redeployment
- Direct editing of generated timeline lines
- Payroll correction workflow for replacing Applied preparations

See also [crew-movement-phases.md](./crew-movement-phases.md) and [payroll.md](../payroll.md).
