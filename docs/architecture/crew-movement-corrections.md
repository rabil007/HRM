# Crew Movement Corrections

Approved corrections are the only path that changes recorded movement fields after the fact. Pending, rejected, and cancelled requests never mutate official `CrewAssignment` / `CrewAssignmentPhase` data.

## Flows

The system provides two authoritative correction paths:

```text
NORMAL FLOW

Crew Operator
    ↓
Request Correction
    ↓
Pending
    ↓
Authorized Manager
    ↓
Approve / Reject
    ↓
Official movement data changes
```

and:

```text
PRIVILEGED FLOW

User with crew_operations.corrections.override + privileged.2fa
    ↓
Correct Movement
    ↓
Select phase + corrected values
    ↓
Reason + impact preview
    ↓
Apply Correction Immediately
    ↓
Official movement data changes
```

Both flows execute the exact same underlying validation engine (`ValidateCrewMovementCorrection`) and mutation pipeline (`ApplyCrewMovementCorrectionPipeline`). The privileged flow records a real `CrewMovementCorrection` record directly in `approved` status (`requested_by = actor`, `decided_by = actor`, `requested_at = now`, `decided_at = now`) and snapshots `applied_values` atomically.

## What can be corrected

| Target | Fields |
|--------|--------|
| Recorded phases (`active` / `completed` with `actual_start_at`) | `actual_start_at`, `remarks` |
| Completed phases | also `actual_end_at` |
| Training | also `details.provider`, `details.course`, `details.course_id` |
| On Vessel (P4) | also assignment `vessel_id`, `rank_id`, `client_id` |

Derived updates on approve / override:

- Completed P6 end → assignment `closed_at`
- Completed Training provider / completion date / course_id → linked `EmployeeTraining` `institute_center`, `issue_date`, `course_id`
- P4 `actual_start_at` or `rank_id` change → if `planned_signoff_source === tour_of_duty`, recalculates `planned_signoff_at` and P4 `planned_end_at` based on the rank's Tour of Duty rule. When the source is `manual_override` or `existing_plan`, the planned dates remain preserved.
- Linked `CrewPlanningAssignment` synchronized with updated rank, vessel, dates, and status.
- Completed P4 changes → linked `EmployeeSeaService` synchronized.

Correcting P1 Travel In updates that phase `actual_start_at` only. It does **not** rewrite `CrewAssignment.started_at`. Assignment `started_at` / `closed_at` describe the assignment lifecycle and are not Crew payroll inputs.

Modern assignments do not create new P1/P3 phases, so the correction picker naturally omits them. Historical assignments that recorded actual P1 or P3 movement remain correctable; the UI may show a small legacy context label on those phases only.

## Hard rules

- Originals are always read from the database at request time
- One pending correction per phase (a direct override is also blocked if an unresolved pending correction exists on that phase)
- No nulling existing values; no topology changes (`phase_code`, `status`, `sequence`, `current_phase_id`, `employee_id`)
- Active phases stay open-ended (cannot add `actual_end_at` via correction)
- Neighbor-phase boundary checks and company-timezone parsing
- **Accommodation chronology integrity**: Resulting timestamps are validated against hotel stays:
  - Pre-join hotel stays must check in on or after arrival and check out on or before P4 join.
  - Post-signoff hotel stays must check in on or after disembarkation and check out on or before return home boundary.
- **Tour of Duty rank consistency**: If P4 `rank_id` is corrected and planned signoff source is `tour_of_duty`, the new rank must have a valid tour rule; otherwise the correction is rejected.
- Active On Vessel conflicts are surfaced in the Crew Operations UI and guided toward Vessel Transfer. Corrections do not add a separate hard block for overlapping P4 intervals; existing timeline, company, and invariant checks still apply. Exact timestamp handoffs remain valid.
- Self-approval in the normal flow is denied unless the actor holds `crew_operations.corrections.override`.
- Privileged direct override requires active 2FA verification (`privileged.2fa` route middleware + domain policy assertion).
- Operational phase status is never flipped to `corrected` for badges — badges come from correction relations.
- **Course correction consistency**: If a Training phase is linked to an `EmployeeTraining` record, free-text `details.course` cannot be modified without `details.course_id`. Structured `details.course_id` must reference an active, valid Course, snapshots the title to `details.course`, and atomically updates `EmployeeTraining.course_id`.

## Permissions

| Permission | Granted from |
|------------|--------------|
| `crew_operations.corrections.view` | roles with `crew_operations.assignments.view` |
| `crew_operations.corrections.request` | roles with `crew_operations.movements.perform` |
| `crew_operations.corrections.approve` | roles with `crew_operations.assignments.update` |
| `crew_operations.corrections.override` | roles with `roles.update` (Owner/admin) |

## Canonical lock order

1. Assignment
2. Correction (if approving an existing pending request)
3. Target phase
4. Linked planning assignment (when present)
5. Linked sea service rows (when present)
6. Linked employee training row (when present for completed Training phase)

All workflows touching both Crew Assignment and Crew Planning (`StartCrewAssignmentFromPlanning`, `CreateCrewAssignmentFromPlanning`, `SaveCrewPlanningAssignment`, `SyncPlanningAssignmentFromCrewAssignment`, `ApproveCrewMovementCorrection`, and `OverrideCrewMovementCorrection`) strictly adhere to this canonical order (`Assignment` 🔒 → `Planning` 🔒) to eliminate deadlock risk.

Then: stale-original conflict check → validate → apply → tour recalculation → invariants → planning sync → sea-service sync (completed P4 only; reject if unsyncable) → training sync (completed P2B only) → mark approved / create approved override record.

Notification failures after commit are reported and never roll back approval. Direct overrides do not dispatch self-decision notifications.

## Impact on crew payroll timesheet freshness

A **pending** movement correction on a phase within a payroll period contributes to the Crew Timesheet source hash (`CrewTimelineSourceHasher`). Creating a new pending correction after a preparation is prepared/approved makes that preparation stale, forcing a new prepare/approve cycle before it can be applied to payroll. Actual movement data changes (actual start/end) and applicable-contract changes are likewise part of the hash. See [crew-payroll-timeline-preparation.md](./crew-payroll-timeline-preparation.md).

## UI entry points

- Assignment show: **Request Correction** (separate from movement actions)
- Crew Operations → **Movement Corrections**
- Crew Operations overview: compact pending/overdue summary for users with correction view permission

## Pending Age tracking

`CrewMovementCorrectionAge` calculates pending age from `requested_at`, falling back to `created_at` for legacy rows. Age is the number of completed calendar days between the request date and today in the active company timezone. The browser clock is not used and derived values are not stored.

| Completed days | Request Status |
|----------------|----------------|
| 0–1 | On Time |
| 2–3 | Needs Attention |
| 4+ | Overdue |

These Age Rules apply only while a correction is pending. Approved, rejected, and cancelled corrections use `not_applicable` and no longer show active Pending Age. Thresholds are centralized in the Age class so they can be made configurable later without changing presenters or queries.

The correction list uses SQL cutoffs derived from the same Age Rules for filtering, priority ordering, and aggregate counts. Pending rows sort overdue → needs attention → on time; non-pending decisions follow newest first. The compound `company_id`, `status`, `requested_at` index supports company-scoped pending lookups.

## Page responsibilities

**Crew Operations overview → high-level correction summary only**

- One compact correction summary
- Pending count
- Overdue count
- Link to Movement Corrections

**Movement Corrections page → detailed correction review and approval**

- Pending Age, Request Status, filters, and priority order
- Requester, assignment, phase, and field counts
- Original/proposed/live comparisons
- Approval, rejection, cancellation, conflicts, and decision history

Detailed correction rows, values, actors, filters, history, and charts are intentionally excluded from Crew Operations. This keeps the overview focused on onboard crew, upcoming joins, sign-offs, manning gaps, movement attention, and operational phase counts.

## Company Timezone Correction UX

Correction dialogs and impact previews strictly follow the Phase 3 Company Timezone standard:

1. **Initial Date Values**:
   - Converted from stored UTC timestamps into the company's local wall-clock time (`YYYY-MM-DDTHH:mm`) using the assignment company's timezone (`CompanyTimezone::forCompanyId($companyId)`).
   - Prevents device browser shifts (e.g., UTC or IST browsers shifting a Dubai 14:00 timestamp or crossing midnight).

2. **Input Pickers & Validation**:
   - Date inputs (`actual_start_at`, `actual_end_at`) show explicit company timezone hints (`Recorded in company time: {timezoneLabel}`).
   - Max attribute caps actual date pickers at `nowInCompanyTime(companyTimezone)`.
   - Proactive client-side warnings highlight if an entered timestamp is in the future relative to the company clock, and the dialog disables progression until resolved.

3. **Impact Previews**:
   - Proposed datetime changes are previewed in the company timezone with 12-hour formatting (`DD-MM-YYYY hh:mm A`).

## Non-goals

- No `EmployeeDeployment` restoration
- No vessel transfer / redeployment via corrections
- No direct editing of assignments without an authoritative correction record (`OverrideCrewMovementCorrection` creates a verified `approved` correction record directly)
- Pending proposals never affect reports’ official dates
- **Void Erroneous Assignment** is a separate privileged workflow (`crew_operations.assignments.void`), not a correction and not Cancel — see [crew-movement-phases.md](./crew-movement-phases.md). Void is conservatively blocked once accommodation history exists until a dedicated accommodation correction/reversal workflow is implemented.
