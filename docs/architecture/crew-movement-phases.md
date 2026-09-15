# Crew Movement Phases

CrewAssignment is the **single source of truth** for crew movement.

```text
Crew Planning
    ↓ confirm / convert
Crew Assignment
    ↓ movement lifecycle
    Crew Assignment Phases
    ↓ completed P4
Employee Sea Service
```

**EmployeeDeployment has been removed.** There is no production deployment data and no legacy backfill is required.

Current Crew, vessel manning actuals, the Crew Operations dashboard pulse, and current/future planning projections require the assignment employee to be **active**. Crew Movement History, completed assignments, and sea service retain inactive/terminated employees. See [Active employee visibility](./active-employee-visibility.md).

## Current Crew and Crew Planning views

`/organization/crew` is the operational Crew Assignments board. It is **not** Crew Planning.

| Surface | URL | Meaning |
|---------|-----|---------|
| **Crew Assignments → Crew View** (default) | `/organization/crew` or `?view=crew` | Employee/assignment-oriented current assignments (Draft/Active unless filtered to history) |
| **Crew Assignments → Vessel View** | `/organization/crew?view=vessel` | Operational vessel-first roster of **currently onboard** crew |
| **Crew Planning → Planning** (default) | `/organization/crew-planning` or `?view=planning` | Planned/future vessel manning and movements (Gantt) |
| **Crew Planning → Onboard by Vessel** | `/organization/crew-planning?view=onboard-vessels` | The same actual/current P4 vessel roster, shown beside planning workflows |
| **Crew Planning → Relief Desk** | `/organization/crew-planning?view=relief` | Operational desk of active P4 crew with upcoming/overdue/missing Planned Sign-Off, derived relief status, and mobilisation readiness |

Crew Planning **Planning** is planned/future state. Crew Planning **Onboard by Vessel** is reusable actual/current P4 operational state. It never derives onboard status from Gantt/planning records. Crew Planning **Relief Desk** is a management view over the same active P4 assignments and existing Planning relief links (`relieves_crew_assignment_id`). It is not a new Relief entity or workflow.

Vessel View / Onboard by Vessel answers: which vessels currently have crew onboard, and who is onboard each vessel.

A person is onboard only when all of the following are true:

```text
CrewAssignment.company_id = trusted current_company_id
CrewAssignment.status = Active
current phase = P4 On Vessel, status Active
vessel_id is present
employee is active in the current company
```

That rule is shared by Crew Assignments Vessel View, Crew Planning Onboard by Vessel, vessel manning actual counts, and the onboard Excel export (`CurrentOnboardCrewQuery`). Planned assignments, P2/P3/P5/P6, `vessel_id` alone, and Crew Planning Gantt bars are not evidence of being onboard.

`planned_signoff_at` on an active P4 row is an operational forecast only. It does not disembark the employee.

Both pages consume the same crew-domain roster (`OnboardByVesselBoard` / `CurrentCrewVesselQuery`). Parent rows are vessels. Each page loads the complete filtered onboard roster for those vessels — assignments are never paginated first.

### Export intent

Excel export uses the same filtered onboard dataset and is not limited to the current page.

| Mode | Request | Result |
|------|---------|--------|
| `all_filtered` (nothing manually selected) | `scope=all` | Export the full filtered onboard set |
| `selected` (one or more rows manually selected) | `scope=selected` + `assignment_ids[]` | Export only IDs that still match the authoritative filtered onboard query |

Selected mode must never silently degrade into `all_filtered`. If every supplied ID is invalid after revalidation, export returns a validation error instead of exporting all matching crew.

Manual selection is persistent across pagination (`allSelectedIds`). Search/filter changes remount the board and clear selection. Switching Planning ↔ Onboard by Vessel, or switching company, also clears selection.

Selection uses the shared `useRecordSelection` hook. `selectedIds` remains the visible-page intersection (Bulk Documents). Onboard export uses `allSelectedIds`. Vessel checkboxes select/deselect that vessel’s loaded crew IDs without the hook knowing what a vessel is.

## Domain model

| Concept | Role |
|------|------|
| **CrewPlanningAssignment** | Planned join/leave on the Gantt board; may convert into a draft assignment. |
| **CrewAssignment** | One mobilisation cycle (P0–P6). |
| **CrewAssignmentPhase** | Ordered occurrence of a phase on that cycle. |
| **EmployeeSeaService** | Historical sea time created from completed P4 phases. |
| **EmployeeTraining** | Formal employee qualification record; optionally synced from completed P2B phases. |

## P0–P6

| Code | Label | User-facing meaning |
|------|-------|---------------------|
| P0 | Pre-Mobilisation | Preparing the crew member before travel. |
| P1 | Travel In | Travelling to the joining location. |
| P2A | Join Standby | Waiting or staying in hotel/accommodation before joining the vessel. |
| P2B | Training | Completing required training before joining. |
| P3 | Ready to Join | Cleared and ready to board the vessel. |
| P4 | On Vessel | Currently onboard the vessel. |
| P5 | Demobilisation Standby | Disembarked and waiting or staying in hotel/accommodation for onward or home travel. |
| P6 | Home / Redeployment | Returned home or moving toward the next assignment. |

These sentences are UI copy only (`crew-phase-descriptions.ts`). They do not change codes, transitions, or timestamps.

**Standby** in Crew Operations means the employee is waiting between movements, typically staying in a hotel or other accommodation. It is not only a system status.

- **P0** is preparation before travel, not boarding.
- **P2A Join Standby** is waiting/staying in hotel/accommodation before joining.
- **P5 Demobilisation Standby** is after disembarkation, waiting/staying in hotel/accommodation for onward or home travel.

## Crew Planning vs Crew Assignment

| Surface | Meaning |
|---------|---------|
| **Crew Planning** | Future intention / scheduling |
| **Crew Assignment** | Actual operational mobilisation cycle |

A Planning record is **not** required before starting an operational assignment. Current Crew exposes one entry point — **Start Assignment** → `/organization/crew/create` — with one crew row by default and **Add Another Crew Member** to start several assignments in one all-or-nothing batch on the same page. The legacy `/organization/crew/bulk-create` route redirects to the unified Create page for bookmarks only. Crew Planning remains the place to record future joins that have not started yet.

**Assignment lifecycle vs payroll:** `CrewAssignment.started_at` and `closed_at` describe the assignment record lifecycle (when the operational cycle was started or closed in OMS). They are **not** Crew payroll inputs. Crew payroll is derived only from eligible actual `CrewAssignmentPhase.actual_start_at` / `actual_end_at` dates. Expected Vessel Join, Planned Sign-Off, Planned Travel Home, and Crew Planning dates are never payable movement dates.

```text
Crew Planning (optional future intention)
    ↓ Start Assignment (review + confirm)
Unified Start Assignment form (/organization/crew/create?planning_assignment_id=…)
    ↓ confirm P0/P1
CrewMovementService::startAssignment()
    ↓
Crew Assignment (operational cycle)
    ↓ movement lifecycle
Crew Assignment Phases
    ↓ completed P4
Employee Sea Service
```

Manual Start Assignment (without Planning) remains available at `/organization/crew/create`.

### Planning → Operational Start handoff

Crew Planning records **future intention only**. Planning dates are forecasts and never become actual movement timestamps automatically.

| Step | Behaviour |
|------|-----------|
| Planning row **Start Assignment** | Opens the unified Create UI with trusted server-side prefill. **Does not** create a `CrewAssignment`. |
| Operations review | Employee, Rank, Client/Vessel, Expected Vessel Join, Remarks, and initial stage (P1 default; P0 optional). |
| Confirm **Start Assignment** | `POST organization/crew-planning/assignments/{planning}/start` → `StartCrewAssignmentFromPlanning` → `CrewMovementService::startAssignment()` inside one transaction. |
| Linking | Original `CrewPlanningAssignment` is linked via `crew_assignment_id`. `relieves_crew_assignment_id` is preserved. `source = crew_planning`. |
| Timestamps | `started_at` and first phase `actual_start_at` use company-local trusted server submit time (`now()`). Planned Join remains `planned_join_at` forecast only. |
| Planned Sign-Off | When present on the Planning row, `planned_leave_date` maps server-side to `CrewAssignment.planned_signoff_at`. It is not editable in the Start handoff and is not an actual disembarkation or payroll date. |
| Permissions | Planning handoff requires `crew_operations.planning.view` **and** `crew_operations.assignments.create` **and** `crew_operations.movements.perform`. Normal manual Start at `/organization/crew/create` (without `planning_assignment_id`) does **not** require Planning view. Backend authorization is mandatory. |
| Master data | Employee, Rank, Client, Vessel, and Expected Vessel Join come from the locked Planning record at Start. The handoff form is read-only for those fields; crafted POST values cannot override them. Update Planning separately when master data is wrong. |
| Linked Active assignment | Redirect to the existing assignment; never create a duplicate. |
| Linked Draft assignment | Backward compatible with the legacy draft-conversion workflow: redirect to the linked draft assignment show page; continue mobilisation from Crew Assignments (Start Travel / draft edits). |
| Active assignment conflict | Reuses `CrewMovementService` active-assignment guards. Transfer Vessel / Redeploy remain separate workflows. |

The legacy `POST organization/crew-planning/assignments/{planning}/create-crew-assignment` route now redirects to the unified Start form for bookmarks. `CreateCrewAssignmentFromPlanning` remains for programmatic draft creation in tests and legacy linked-draft compatibility.

This phase does **not** redesign Crew Planning or spreadsheet import.

## Start Assignment

Manual create uses `submission_intent = start | draft`. Domain logic lives in `CrewMovementService::startAssignment()` (shared with Bulk Add Crew). The HTTP controller only authorizes, validates, and redirects.

### Start (primary)

Requires `crew_operations.assignments.create` **and** `crew_operations.movements.perform`.

Creates:

- `CrewAssignment.status = Active`
- `started_at` = company-local submit time (`now()` in the company timezone)
- one Active starting phase (`sequence = 1`, `actual_start_at` = the same submit timestamp)
- `source` remains the existing manual source
- `planned_join_at` stores **Expected Vessel Join** (forecast only)

Default current assignment stage is **P1 Travel In**. Operations may optionally start at **P0 Pre-Mobilisation**. Direct start at P2A, P2B, P3, P4, P5, or P6 is rejected so payable Join Standby history is not skipped (P0/P1 are payroll-excluded; P2A/P2B/P3 are Sign-On Standby). Join Vessel remains the only way to enter P4. Redeploy may still start later phases on a new linked assignment.

Prior phases are **never invented**. A P1 start has only P1 in the timeline.

The create form does **not** collect Assignment Start Date & Time. Normal `/organization/crew/create` Start Assignment always uses company-local server submit time (`now()` in the company timezone). The Store request and controller do **not** accept, validate, or forward a client-supplied `stage_started_at`; crafted timestamps cannot backdate or future-date a normal web start. `CrewMovementService::startAssignment()` may still accept an explicit timestamp internally for tests, Bulk Add Crew, and later historical import.

Quick create does **not** accept Planned Sign-Off or Planned Travel Home. Those columns remain on the assignment for P4 Plan Sign-Off, Confirm Disembarkation, Crew Planning, and Movement Correction. Normal Edit Assignment does not expose or mutate them.

Start Assignment does **not** snapshot Tour of Duty, create Sea Service, mark the employee On Vessel, or create P4. Expected Vessel Join never becomes P4 `actual_start_at`. `CrewAssignment.started_at` is the assignment lifecycle timestamp and is not a payroll input; the first phase `actual_start_at` is recorded as the same company-local submit instant for operational history.

`SyncPlanningAssignmentFromCrewAssignment` still runs. A manually started pre-P4 assignment is **not** forced to manufacture a new Planning row when Planned Sign-Off is absent. Existing linked Planning rows stay linked.

### Bulk Add Crew (unified Create UI)

Single and bulk start share one Create UI at `/organization/crew/create`. Current Crew does not expose a separate Bulk Add Crew action. One crew row uses the normal Store path; **Add Another Crew Member** (Start capability only) switches the same page into bulk mode (2+ rows) and posts to the existing atomic bulk Store endpoint. Save as Draft remains **single-row only** for create-only users who lack `crew_operations.movements.perform`. The legacy `/organization/crew/bulk-create` URL redirects to the unified Create page for bookmarks; create-only users still receive one row even when `?mode=bulk` is present.

Bulk mode does not introduce a bulk-specific lifecycle, batch table, or spreadsheet import.

```text
Start Crew Assignment (/organization/crew/create)
    ↓ one crew row by default
    ↓ optional Add Another Crew Member
    ↓ common Client / Vessel / Expected Join / initial stage / remarks
    ↓ per-employee Rank
BulkStartCrewAssignments (one transaction, 2+ rows only)
    ↓ CrewMovementService::startAssignment() for each employee
Current Crew
```

| Rule | Behaviour |
|------|-----------|
| Permissions | Same as Start Assignment: `crew_operations.assignments.create` **and** `crew_operations.movements.perform`. Frontend `can.start` is UX only. Bulk mode and **Add Another Crew Member** require Start capability; create-only users stay in Single/Draft mode. |
| Common fields | Client, Vessel, Expected Vessel Join (`planned_join_at`), initial stage, remarks. Client/Vessel auto-resolution reuses `ClientAssignmentRules`. |
| Per-row fields | Employee and Rank only. Rank still defaults from the employee profile. |
| Starting stages | P1 Travel In default; P0 Pre-Mobilisation optional. Direct P2A/P2B/P3/P4/P5/P6 starts are rejected. |
| Timestamp | One company-local server timestamp for the whole successful batch. The HTTP request does not accept `stage_started_at`, `started_at`, or browser-supplied company IDs. Each assignment `started_at` equals its initial phase `actual_start_at`. |
| Atomicity | All-or-nothing. Every visible bulk row must have a selected employee or be removed by the user; incomplete, blocked, or invalid rows prevent the entire batch. If any row is invalid or the employee already has an Active assignment, **no** assignments from that batch are committed. Partial success / Skip Blocked Rows is not in this phase. |
| Active assignment | Reuses `startAssignment()` locking and `assertNoActiveAssignment()`. On Vessel and other Active phases block the row/batch; Transfer Vessel remains the existing movement, not an automatic bulk action. |
| Payroll / sea service | Unchanged. P0/P1 stay payroll-excluded. Bulk P0/P1 does not create `EmployeeSeaService` or invent P2A/P3/P4. Planning sync still runs through `startAssignment()`. |

### Save as Draft (optional)

Requires only `crew_operations.assignments.create`.

Keeps the previous Draft semantics:

- `CrewAssignment.status = Draft`, `started_at = null`
- Planned P0 with `actual_start_at = null`
- Current Assignment Stage is not required; start timestamps are not collected

Existing Draft assignments remain operable.

### Edit Assignment

The edit form updates assignment master data and Expected Vessel Join (`planned_join_at`) plus remarks. Current Assignment Stage is read-only context. The form does **not** expose Assignment Start Date & Time, Planned Sign-Off, Planned Travel Home, or editable actual movement timestamps. Stored `planned_signoff_at` / `planned_travel_at` remain on the record and continue to be owned by P4 Plan Sign-Off, Confirm Disembarkation, Crew Planning, and Movement Correction. The update request accepts only `rank_id`, `client_id`, `vessel_id`, `planned_join_at`, and `remarks`. It does not accept `started_at`, `current_stage`, phase `actual_start_at` / `actual_end_at`, `planned_signoff_at`, or `planned_travel_at`. Omitting those fields preserves existing stored values. If Expected Vessel Join is submitted and an existing Planned Sign-Off is present, the join date cannot be after that sign-off date (company-local calendar dates). The update does not silently change or clear Planned Sign-Off. Historical/actual movement corrections remain on Movement Actions and Request Correction. Correcting P1 Travel In updates that phase `actual_start_at` and does **not** rewrite `CrewAssignment.started_at`.

### Start Travel (`approve_mobilisation`)

User-facing label is **Start Travel**. The persisted action value remains `approve_mobilisation` for historical activities, tests, and old Draft records. The Start Travel form does **not** depend on Planned Travel Home; that forecast belongs to later P5 → P6 Travel Home / history workflows.

| Case | Behaviour |
|------|-----------|
| **A — Active P0** | Complete P0 using its existing `actual_start_at`; open Active P1 at `occurred_at`; preserve `assignment.started_at` |
| **B — legacy Draft P0** | Activate the assignment, complete Planned P0 with the existing `completePhase` fallback (`actual_start_at` = `actual_end_at` = `occurred_at`), open Active P1. Do not manufacture a historical P0 start from a later travel time |

No approval step is required between Active P0 and Start Travel.

### Active P0 conflict

| State | `has_active_assignment` |
|-------|-------------------------|
| Draft P0 | `false` |
| Active P0 | `true` |

An employee already in Active P0 cannot start another assignment, the same as P1–P6. Transfer Vessel remains the path when the selected employee is already On Vessel on another vessel.

## Tour of Duty

When Join Vessel creates active P4, the system resolves Tour of Duty directly from Global Rank Master (`ranks.max_tour_of_duty_days`) and suggests Planned Sign-Off.

### Resolution

Tour of Duty is global per Rank (`ranks.max_tour_of_duty_days`). There are no company policies or assignment overrides.

### Calculation (company timezone)

```text
suggested planned sign-off local date
    = actual P4 join local date + applied Tour of Duty days
```

Operational values for active P4:

```text
days_onboard        = whole local calendar days between actual join and today
current_duty_day    = days_onboard + 1
remaining_tour_days = planned sign-off local date − today (may be negative)
tour_progress_percent = days_onboard / applied Tour of Duty days
```

Display percentage may be clamped to 0–100; remaining days stay negative when overdue.

### Snapshot behaviour

After P4 join, `crew_assignments.tour_of_duty_days` stores an integer snapshot. Later changes to Rank Master do **not** rewrite existing assignments. New joins use the latest Rank Master value.

Rank Master changes never automatically rewrite assignments that already have a Tour snapshot.

### Late Tour application

If an employee entered active P4 without a Tour snapshot because their Rank had no Tour configured, a later Rank configuration does not automatically rewrite the assignment.

Operations may explicitly apply the missing Tour via the **Apply Tour of Duty** action on the assignment show page, or administrators can run the safe bulk Artisan command:

```bash
php artisan crew:apply-missing-tour-of-duty --company=1
```

The repair:

- snapshots the current Rank Tour (`tour_of_duty_days`)
- uses the original actual P4 join date (`actual_start_at`)
- generates Planned Sign-Off only when one is missing (`planned_signoff_at = actual P4 join + tour days`)
- preserves existing manual/existing-plan dates and override reasons
- syncs linked Crew Planning (`planned_leave_date`)
- is audited under `late_tour_of_duty_applied`

Ineligible assignments (draft, pre-P4, completed, cancelled, assignments with existing snapshots, or assignments whose rank still has no Tour configured) are never modified. Dry-run (`--dry-run`) performs zero mutations.


### Planned versus actual

- Planned Sign-Off is an expected date only (`planned_signoff_at` / P4 `planned_end_at`).
- A generated Planned Sign-Off must **never** complete P4, disembark the employee, close the assignment, create payroll days, or create Sea Service.
- Actual disembarkation remains a separate `confirm_disembarkation` action.

### Join Vessel sign-off choices

| Choice | Behaviour |
|--------|-----------|
| `tour_of_duty` | Use calculated suggestion |
| `existing_plan` | Keep existing Planning/assignment planned date (never silently overwritten) |
| `manual_override` | Enter another date; date and reason are both required when this choice is explicit |

If no Tour exists and no Planned Sign-Off is entered (and no explicit manual choice was supplied), join still succeeds and attention warnings surface `missing_tour_of_duty` / `missing_planned_signoff`.

### Tour status filters vs dashboard counts

`due_within_7_days`, `due_within_14_days`, and `due_within_30_days` Current Crew filters are **cumulative** (include due today and nearer windows) so they match Crew Operations dashboard “within N days” cards. Exclusive internal buckets remain only for analytics rollups. `due_today` stays independently filterable. Planned sign-off overdue uses company-local calendar dates so due-today assignments are never also overdue.

### Correction recalculation

When an approved correction changes P4 `actual_start_at` and `planned_signoff_source` is `tour_of_duty`, Planned Sign-Off is recalculated from the **snapshotted** tour days and written to:

1. `crew_assignments.planned_signoff_at`
2. P4 `crew_assignment_phases.planned_end_at`
3. Linked `crew_planning_assignments.planned_leave_date` (via `SyncPlanningAssignmentFromCrewAssignment` in the same approval transaction)

Manual / existing-plan sources are preserved. Pending corrections do not mutate official dates. A failure during approval rolls back assignment, phase, planning, and correction status together.

### Transfer / redeployment (Phase 2C.1)

Direct vessel transfer and direct-P4 redeploy create a **new linked assignment** (`previous_assignment_id`). They never mutate the source assignment into another vessel.

- Source P4 is completed at the actual handoff `occurred_at`; Sea Service syncs from that completed source P4 only.
- Destination starting in active P4 receives a **fresh Tour of Duty snapshot** via `CrewTourOfDutyResolver` + `CrewJoinVesselSignoffApplier` (same path as Join Vessel), based on **destination rank** and the handoff timestamp — not a copy of the source Tour.
- Redeploy to P0/P1/P2A/P3 does **not** snapshot Tour; Tour is applied later when that assignment performs Join Vessel.
- Planned Sign-Off remains forecast-only; only actual transfer/redeploy/`occurred_at` completes source P4.

### Notifications deferred

Email, browser Web Push, in-app notification feeds, escalation, and Announcement records for Tour of Duty are **not** implemented in Phase 1.

## Supported movement actions

| Action | Typical from phase |
|--------|--------------------|
| `approve_mobilisation` | P0 (Active or legacy Draft). User-facing label: **Start Travel** |
| `record_arrival` | P1 → P2A or P3 |
| `start_join_standby` | P1/P3 path helpers |
| `send_to_training` | P2A → P2B |
| `complete_training` | P2B → P2A or P3 |
| `mark_ready` | P2A → P3 |
| `join_vessel` | P3 (or direct paths) → P4 |
| `plan_signoff` | P4 plan only (does not disembark) |
| `confirm_disembarkation` | P4 → P5 or P6 |
| `start_demob_standby` | helper into P5 |
| `travel_home` | P5 → P6 |
| `close_assignment` | P6 → Completed |
| `cancel_assignment` | Draft/Active → Cancelled (not from active P4) |
| `void_erroneous_assignment` | Privileged admin cleanup (any P0–P6; separate route) |

## Void Erroneous Assignment

**Void** is not Cancel.

| | Cancel Assignment | Void Erroneous Assignment |
|--|-------------------|---------------------------|
| Meaning | Legitimate assignment stopped | Assignment / movement entered by mistake |
| Typical use | Client cancelled; mobilisation abandoned | Wrong employee, duplicate, erroneous progression |
| Permission | `crew_operations.assignments.cancel` | `crew_operations.assignments.void` |
| Phases | Not from active P4 | May be attempted from any P0–P6 |
| Result | Status `Cancelled` (record remains) | Soft-delete + void metadata; removed from active ops |
| Fake movements | Does not invent disembarkation | Does not invent disembarkation |

Void requires the dedicated permission **and** passes `CrewAssignmentVoidGuard`. Downstream blockers include:

- `payroll_applied` / `payroll_protected` — Applied, Approved/Submitted timeline prep, paid/approved work allocations, or timesheet segments
- `sea_service_exists` — linked `EmployeeSeaService` (never cascade-deleted)
- `linked_assignment_exists` — transfer/redeploy children via `previous_assignment_id`
- `already_voided` — already voided / soft-deleted

HTTP: `POST /organization/crew/{assignment}/void` (`organization.crew-assignments.void`) via `VoidCrewAssignment` Support action (transaction + `lockForUpdate()`). Linked assignment-derived planning bars are soft-deleted; phase history is retained under the soft-deleted assignment.

See also [crew-movement-corrections.md](./crew-movement-corrections.md).

## Linked assignment actions

### Transfer Vessel (`transfer_vessel`)

Available from Active P4 On Vessel. Completes the source P4 and assignment at `occurred_at`, syncs sea service and planning for the source, then creates a linked Active assignment (`previous_assignment_id`, `source = vessel_transfer`) that starts directly in active P4 on the destination vessel. Destination vessel must start blank in the form, must differ from the source, and is required. Destination **Client** defaults from the destination Vessel’s current `client_id` (not the source assignment Client). An explicitly submitted destination Client must match that vessel Client when the vessel is assigned. Rank may still default from the current assignment. No artificial P5/P6/P0–P3 phases are created. The destination receives a fresh Tour of Duty snapshot (destination rank + handoff timestamp) via the same resolver/applier as Join Vessel. The movement controller redirects to the new assignment.

#### Intelligent transfer recommendation

Active On Vessel conflicts are proactively surfaced in the UI and the recorder is guided toward Vessel Transfer when appropriate.

If a recorder selects an employee who is already actually On Vessel (active P4) in the same company, and tries to place them on a different vessel, Crew Operations recommends **Transfer Vessel** instead of creating another assignment or joining vessel on a second record.

The warning identifies the employee, current assignment, current vessel, active P4 state, and when that P4 started. Creating or joining another vessel assignment may produce conflicting operational history.

This appears when:

- creating a draft **or starting** an assignment and the selected employee is currently On Vessel, with a destination vessel that differs from the current vessel
- recording Join Vessel on another assignment for a different destination vessel

**Use Transfer Vessel** opens the existing Transfer Vessel action on the current On Vessel assignment and prefills destination vessel, rank, client, and movement time when those values were already entered (Visa Type is not stored on Crew Assignments). Query parameters are convenience only. The recorder must still review and submit the movement. The mutation still goes through `transfer_vessel` and backend company ownership checks. The system does not create the linked assignment in the browser and does not rewrite history.

#### Employee operational status on assignment creation

On the Start Crew Assignment form (`/organization/crew/create`), selecting an employee immediately displays their tenant-scoped operational status directly within the Employee selection panel (via `CrewAssignmentStatusResolver::forEmployeeIds()`).

Operations immediately sees:
- **On Vessel (P4)**: High-attention amber warning showing current vessel, assignment number, start time, and days onboard, with transfer recommendation when another vessel is selected.
- **Join Standby (P2A)**: Informational badge showing assignment number, start time, and days in standby.
- **Demob Standby (P5)**: Distinct P5 demobilisation standby badge with assignment number and duration.
- **Available / In Home**: Calm status indicating the employee is ready without operational conflict.
- **Other active phases (P0, P1, P2B, P3, P6)**: Accurate current phase status and duration.

Same vessel does not recommend a transfer. A planned future assignment, a completed or cancelled tour, or another company's assignment is not treated as a current vessel transfer. The current assignment is excluded from its own recommendation.

This recommendation is not a new backend hard block. Existing assignment invariants still apply: an employee cannot have two active assignments, and Transfer Vessel / Redeploy still require an authorised movement and a company-owned destination. Historical movement corrections remain the path for repairing already-recorded dates.

#### Direct transfer vs a real gap

A direct handoff is an exact timestamp boundary:

```text
HEA KRAKEN P4 ends   26 Aug 16:30
PLB 648 P4 starts    26 Aug 16:30
```

That is the Transfer Vessel case. Intervals are half-open: a genuine overlap is `left.start < right.end` and `right.start < left.end`. Equal start/end is a valid handoff, not an overlap.

This is not a transfer:

```text
HEA KRAKEN ends      23 Aug 16:30
24–25 Aug            uncovered / standby / home / other state
PLB 648 starts       26 Aug 16:30
```

That gap can stay a normal assignment or redeploy. Do not rewrite it as a transfer. Payroll timeline overlap detection remains independent of this UI recommendation.

### Redeploy (`redeploy`)

Available from Active P5 or P6. Completes the source phase and assignment, then creates a linked assignment (`source = redeployment`) starting only at the chosen real phase: P0 (Draft + planned; vessel optional; planned sign-off cleared when not applicable), or P1 / P2A / P3 / P4 (Active; vessel optional except P4 requires vessel and rank). Same or different vessel/client is allowed. Direct P4 redeploy applies a fresh Tour snapshot; pre-P4 starts do not — Tour is applied later on Join Vessel. Hidden stale destination fields must not be submitted for P0. Earlier phases are never invented.

### Still unsupported as an immediate movement action

```text
correct_movement
```

Movement field corrections use a dedicated approval workflow instead of `correct_movement`. See [crew-movement-corrections.md](./crew-movement-corrections.md).

## Generic Assignment Editing

Generic Crew Assignment editing is limited to Draft/pre-P4 preparation. Once P4 begins, planned sign-off changes use Plan Sign-Off, historical field changes use Movement Corrections, and actual operational changes use movement actions.

## Mobilisation Readiness (advisory)

Mobilisation Readiness currently derives from required-document compliance (`DocumentRequirementResolver` / `DocumentComplianceQuery`). Training is not included in the score. There is no readiness table and **no movement blocker**. Readiness is advisory only and never blocks Crew movement.

`CrewMobilisationReadinessResolver` answers whether the assignment employee looks operationally ready to mobilise based on required documents. It is shown on Crew Assignment show (full card) and as a compact indicator on Current Crew lists for **pre-join** assignments (P0–P3). Zero applicable checks are shown as **No Checks Configured** (neutral presentation; overall status remains Ready so P0 may still recommend Start Travel).

| Status | Meaning |
|--------|---------|
| Ready | Required-document checks are configured and none have known problems |
| No Checks Configured | Zero applicable required-document checks (presentation only; not a separate movement status) |
| Attention | Expiring-soon required documents |
| Not Ready | Required documents missing or expired (`critical` check severity) |

Check severities are `ok` (valid), `warning` (expiring), and `critical` (missing or expired). `critical` maps to Not Ready. None of these severities block `CrewMovementService`.

On Current Crew **mobile** cards, existing assignment operational warnings take priority over the readiness summary (including Ready and Not Ready). The desktop table still shows the readiness badge.

Operators may still perform any movement already allowed by `CrewMovementAvailableActions` / `CrewMovementService`. Readiness never adds override, waiver, or acknowledgement steps.

The Documents shortcut is omitted unless the user has `documents.view`.

## Recommended Next Action (advisory)

`CrewAssignmentRecommendedActionResolver` picks **one** suggested next step from the current `available_actions` result (or a non-movement hint such as resolve readiness / plan relief).

It does **not** replace the allowed-action menu. `More Actions` remains the complete backend-allowed set. Operators may ignore the recommendation. Permission checks for `crew_operations.movements.perform` and `crew_operations.assignments.cancel` still apply on the server.

Typical suggestions:

| Phase | Usual recommendation |
|-------|----------------------|
| P0 (ready or no checks configured) | Start Travel (`approve_mobilisation`) |
| P0 (readiness issues) | Resolve readiness, with Start Travel Anyway |
| P1 | Record Arrival |
| P2A | Join Vessel |
| P2B | Complete Training |
| P3 | Join Vessel |
| P4 | Confirm Disembarkation (or Plan Relief when sign-off is near and relief is not ready) |
| P5 | Travel Home |
| P6 | Close Assignment |

## Permissions


Use Spatie permission names:

```text
crew_operations.assignments.view
crew_operations.assignments.create
crew_operations.assignments.update
crew_operations.movements.perform
crew_operations.assignments.cancel
crew_operations.assignments.void
crew_operations.corrections.view
crew_operations.corrections.request
crew_operations.corrections.approve
crew_operations.corrections.override
crew_operations.settings.view
crew_operations.settings.update
audit.view
```

Legacy `crew_operations.deployments.*` permissions are removed and migrated onto assignment permissions.

Save as Draft requires `crew_operations.assignments.create`. Start Assignment requires that permission **and** `crew_operations.movements.perform` (`CrewAssignmentPolicy::start()`). Frontend `can.start` is UX only.

## Movement service

`CrewMovementService` runs every create/action in a company-scoped transaction with `lockForUpdate()`, invariant checks, and atomic phase updates. `startAssignment()` is the shared operational create path. Completed P4 (`actual_end_at` set) syncs sea service via `SeaServiceSyncService` in the same transaction.

Tour resolution uses `CrewTourOfDutyResolver` / `CrewTourOfDutyCalculator`. Progress and status buckets use `CrewTourProgress` / `CrewTourStatusQuery`.

### Client snapshots vs vessel current Client

```text
Client
├── Projects          (current Project → Client; re-parenting blocked when Employees conflict)
└── Vessels           (Vessel.client_id = current/default operational Client)

CrewAssignment.client_id      = Client during that mobilisation cycle (snapshot)
EmployeeSeaService.client_id  = Client during that service period (from assignment snapshot)
```

`projects.client_id` / `vessels.client_id` stay nullable only for legacy unassigned rows. Mapped records cannot clear Client back to null in normal editing. Project Client changes (including first-time `null` → Client) that would leave Employees with a mismatched Client are rejected.

New operational Crew activity cannot use a legacy-unassigned Vessel, an inactive Vessel, or an active Vessel whose assigned Client is inactive. When `CrewMovementService::createDraft()` or `startAssignment()` receives a `vessel_id`, it asserts the Vessel is company-owned and active, then snapshots that Vessel’s current **active** Client (and rejects null-client / inactive-client / mismatched Client). Crew Planning create/update vessel options and validation require an active company Vessel with an assigned active Client; Planning → Assignment conversion relies on the same draft invariant.

Editable pre-P4 Crew Assignments may retain an unchanged legacy or inactive Vessel/Client snapshot during unrelated field edits (remarks, planned dates, rank). Changing Vessel or Client on that record applies today’s strict operational rules — an inactive existing Vessel cannot participate in a Client-only change.

Current Crew and Relief Desk Client/Vessel filter options that cascade by Client are derived from stored `crew_assignments` Client↔Vessel pairs, not from today’s `Vessel.client_id`. Historical Movement History filters continue to query assignment snapshot columns independently.

`SeaServiceSyncService` copies `CrewAssignment.client_id` into `EmployeeSeaService.client_id`. Changing `Vessel.client_id` later must **never** rewrite historical assignments, phases, timesheets, payroll, or sea service. Sea Service create/edit must not force historical rows to match the Vessel’s current Client; inline Vessel creation from Sea Service requires an explicit current Client (and Vessel Type) for the new master record.

## P2B Training → Employee Training synchronization

Crew Operations operationally tracks training during mobilisation within `CrewAssignmentPhase` (code `Training` / `P2B`). Employee Training separately maintains the employee's formal qualification and certificate history. The two domains are integrated conditionally without conflating their responsibilities.

```text
CrewAssignmentPhase P2B (Training)
         │
         │ complete_training (if company toggle enabled & not skipped)
         ▼
  EmployeeTraining (Authoritative HR qualification)
```

### Domain separation
- **CrewAssignmentPhase (P2B)**: Answers "what happened during this mobilisation?" Stores operational history, provider remarks, and dates within the assignment lifecycle.
- **EmployeeTraining**: Answers "is the seafarer qualified, and what certificates do they hold?" Authoritative HR/crew records used for audits, qualifications, and matrices.

### Company Setting & Governance
- Controlled per company under **Crew Operations → Settings → Assignment Settings** via `sync_training_to_employee_training` (boolean column on `crew_operations_settings`, defaults to `false` / OFF).
- Toggling the setting logs an activity log entry (`updated crew operations training sync setting`).
- **Prospective only**: Turning the toggle ON does not retroactively scan or backfill previously completed P2B phases.

### Eligibility & Completion Guard
- Only completed P2B phases (`status === CrewPhaseStatus::Completed`) with an explicit completion timestamp (`actual_end_at !== null`) may synchronize.
- Active, cancelled, or pseudo-status phases are strictly ineligible.
- There is **no `now()` completion fallback** or synthetic completion date; planned dates must never be substituted. If a phase lacks `actual_end_at`, sync is blocked.

### Data Ownership & Field Separation
Crew Operations and HR maintain strict separation of owned fields on `EmployeeTraining`:
- **Crew-owned synchronized fields**:
  - `course_id`: Selected active company course ID.
  - `issue_date`: `actual_end_at` converted to the company's local timezone via `CompanyTimezone::forCompanyId($companyId)`.
  - `institute_center`: Training provider name from phase details (`details['provider']`).
- **HR-owned preserved fields**:
  - `expiry_date`, `country_id`, `certificate_path`, `certificate_original_filename`, `certificate_mime_type`, `certificate_size_bytes`, `current_version`, `replaced_at`, etc.
  - When creating a new record, HR fields are initialized to `null`.
  - On re-sync or correction sync, Crew Operations updates **only** crew-owned fields. HR-managed fields are **never erased or overwritten with null**.

### Course Resolution & Correction Consistency
- Synchronization strictly requires a valid, active `course_id` belonging to the company (`Course::class`).
- Free-text course names never trigger fuzzy-matching or automatic course creation.
- If sync is active, the operator must pick a course from the company course catalog during `send_to_training` or `complete_training`.
- **Course Correction Consistency**: When a P2B phase is already linked to an `EmployeeTraining`, free-text `details.course` cannot be modified in isolation. Corrections must supply `details.course_id`, which validates against active courses, snapshots the course name to `details.course`, and atomically updates `EmployeeTraining.course_id`. Free-text-only corrections on linked phases are rejected with `correction_training_course_locked`.

### Occurrence-Level Control
- When the company toggle is enabled, the **Complete Training** movement dialog displays a pre-checked toggle: *"Add to Employee Training record"*.
- Operators may uncheck this to skip HR sync for non-certifying or ad-hoc briefings.
- If the company toggle is disabled, this option is omitted and sync is bypassed entirely.

### Field Mapping (Initial Creation)
| EmployeeTraining Field | Source / Rule |
|-----------------------|---------------|
| `company_id` | Assignment company ID (strictly tenant-isolated) |
| `employee_id` | Assignment employee ID |
| `course_id` | Selected active company course ID |
| `issue_date` | Actual P2B completion date (`occurred_at` / `actual_end_at`) converted to the company's local timezone via `CompanyTimezone::forCompanyId($companyId)`. Never uses planned dates or `now()`. |
| `institute_center` | Training provider name from phase details (`details['provider']`) |
| `expiry_date` | `null` on creation; preserved on subsequent re-sync |
| `country_id` | `null` on creation; preserved on subsequent re-sync |
| `certificate_path` | `null` on creation; preserved on subsequent re-sync |
| `source_crew_assignment_phase_id` | FK to the completed `crew_assignment_phases.id` |
| `sort_order` | `EmployeeTraining::where('employee_id', ...)->max('sort_order') + 1` |

### Idempotency & Invariants
- `source_crew_assignment_phase_id` has a unique constraint on `employee_trainings`. Repeating completion or re-running sync updates the existing record's crew-owned fields rather than creating duplicates.
- The sync executes inside the same database transaction as the movement action in `CrewMovementService::completeTraining()`.
- Voiding an assignment preserves the employee's formal training history (`foreignId('source_crew_assignment_phase_id')->nullable()->nullOnDelete()`).
- Approved movement corrections on a P2B phase atomically update the linked `EmployeeTraining` (`institute_center` from provider, `issue_date` in company timezone if `actual_end_at` is corrected, and `course_id` if `details.course_id` is corrected), while keeping HR-owned fields intact.
- Turning the company toggle OFF never deletes, unlinks, or hides previously synchronized records. Turning it ON never backfills historical phases.

### Cross-Domain Navigation
- **Crew Assignment Timeline**: Displays a *"✓ Added to Employee Training"* badge linking directly to the employee's training record (`/organization/employees/{employee}/trainings/{id}`).
- **Employee Training Show View**: Displays a prominent source banner (*"Source: Crew Operations · P2B Training"*) and links back to the originating Crew Assignment.

## Planning

Bidirectional sync:

1. **Planning → Assignment** — `CreateCrewAssignmentFromPlanning` creates a draft (`source = crew_planning`), links `crew_planning_assignments.crew_assignment_id`, then runs `SyncPlanningAssignmentFromCrewAssignment` so the original planning row is reused (no duplicate).
2. **Assignment → Planning** — `SyncPlanningAssignmentFromCrewAssignment` creates/updates the linked planning bar after Crew Assignments create/update and after every `CrewMovementService::perform()` action.

### Date precedence (Assignment → Planning)

- Join: `P4.actual_start_at` → `planned_join_at`
- Leave: `P4.actual_end_at` → `planned_signoff_at` → `P4.planned_end_at` → `null` (open-ended active P4)

Actual disembarkation replaces planned sign-off on the planning bar. Planned sign-off is never treated as actual disembarkation.

### Open-ended P4

Active P4 without planned/actual leave may store `planned_leave_date = null`. Gantt includes those rows (`planned_leave_date IS NULL` overlaps the range). Display `end` uses the requested Gantt `to` date only; it is not persisted. Payload includes `is_open_ended: true`.

### Linked-row ownership

Once `crew_assignment_id` is set, Crew Assignments is source of truth. Planning update/delete of linked rows is rejected; the UI links to the assignment instead.

### Cancellation

- Cancelled before any completed P4: soft-delete the linked planning bar.
- Completed P4 history: preserve the planning bar with actual join/leave dates.
- Incomplete pre-P4 eligibility (missing vessel/dates) does **not** delete an existing planning-origin row.

### Idempotency

Lookup by `crew_assignment_id` (unique), restore soft-deleted linked rows, never create a second planning row for one assignment.

Relief linking uses `relieves_crew_assignment_id`. Gantt `is_assigned` is true when `crew_assignment_id` is set.

## Phase 2A — Crew Relief Readiness

Crew Planning remains the management surface for creating and editing relief plans. Current Crew, Assignment Show, and the Crew Operations dashboard display derived readiness and risk; they do not introduce a separate Relief workflow or table.

### Derived readiness statuses

Resolved by `CrewReliefReadinessResolver` from the active operational Planning row where `relieves_crew_assignment_id` equals the onboard source assignment:

| Status | Meaning |
|--------|---------|
| `no_relief` | No active relief Planning row |
| `relief_planned` | Planning row exists; `crew_assignment_id` is null |
| `assignment_created` | Linked draft / not-yet-mobilising assignment |
| `mobilising` | Linked assignment in P0–P2B movement |
| `ready_to_join` | Linked assignment active P3 |
| `relief_onboard` | Linked assignment active P4 with actual join |

Soft-deleted Planning rows, cancelled or completed linked assignments, and linked Active assignments whose current phase is P5 or P6 do not count as operational relief. Operational linked relief is limited to Draft/Active assignments still in P0–P4. Vacant relief slots (null `employee_id`) still require source P4 / vessel / rank / duplicate validation. Authoritative duplicate and employee checks run inside `SaveCrewPlanningAssignment` after locking the source assignment.

### Risk (company-local calendar days until Planned Sign-Off)

| Condition | Risk |
|-----------|------|
| Ready to join or relief onboard | `none` |
| More than 14 days | `none` (unless otherwise invalid) |
| 14 days or fewer and not ready/onboard | `warning` |
| 7 days or fewer, due today, or overdue and not ready/onboard | `critical` |

### Workflow

1. Current Crew → **Plan Relief** opens Crew Planning with vessel/rank/source/prefill join (= source Planned Sign-Off).
2. Crew Planning creates/edits the row (`relieves_crew_assignment_id` set; same vessel/rank; one active relief per source).
3. `CreateCrewAssignmentFromPlanning` converts the same Planning row (preserves the relief link).
4. Real P0–P4 movement progresses on the linked assignment; readiness recalculates from phase.

Planning never starts movement, completes source P4, creates Sea Service, or creates payroll actuals.

### Relief Desk

`/organization/crew-planning?view=relief` is the operational workspace for upcoming crew changes. Source rows are company-scoped **active P4** assignments with an operationally active employee. The default horizon is **next 30 days plus overdue**, and assignments **missing Planned Sign-Off** still surface because Operations cannot plan relief without a forecast.

Status and risk come only from `CrewReliefReadinessResolver` / `CrewReliefStatusQuery` semantics. Mobilisation Readiness on a linked pre-join relief assignment is **advisory** (`CrewMobilisationReadinessResolver`) and never blocks planning or movement. Ready to Join / Relief Onboard never automatically disembarks the source crew.

Row actions reuse existing routes: Plan Relief opens the Planning create sheet with vessel/rank/`relieves_crew_assignment_id`/join date prefill; Open Relief Plan stays on Planning and targets the existing bar via `planning_assignment_id` (opens the current edit sheet when the bar is in range); Open Relief Assignment / Review Source Assignment use Crew Assignment show when `crew_operations.assignments.view` is granted.

Default desk order is operational urgency, then nearest Planned Sign-Off:

1. Overdue
2. Due today
3. Critical relief risk / sign-off within 7 days
4. Missing Planned Sign-Off
5. Warning relief risk / sign-off within 14 days
6. Normal / Good, then later cases

The quick-view strip uses the `not_ready` focus key and labels it **Relief Not Ready** (replacement not yet operationally ready), which is distinct from Mobilisation Readiness **Not Ready**.

Saved Views are not used on Relief Desk (Crew Planning has no Saved Views page key). Projected Manning context is deferred; the desk does not add a second projection engine.

### Deferred

- Phase 3: notifications (email, push, in-app, escalation)

## Phase 2B.1 — Projected Vessel Manning Engine

Read-only Support query: `CrewProjectedManningQuery`. Derives coverage from existing Crew Assignments, P4 phases, Crew Planning rows, relief links (`relieves_crew_assignment_id`), and company-scoped `VesselManning`. No projection table; no mutations.

### Data sources

| Input | Use |
|-------|-----|
| `VesselManning` | Required count per vessel + rank |
| P4 `actual_start_at` / `actual_end_at` | Actual onboard intervals and authoritative dates |
| Assignment `planned_join_at` / `planned_signoff_at` | Forecast when actuals absent |
| P4 `planned_end_at` | Forecast leave fallback |
| Planning `planned_join_date` / `planned_leave_date` | Planning-only joins; lowest precedence when linked |

### Join precedence

1. P4 `actual_start_at`
2. Assignment `planned_join_at`
3. Linked / planning-only `planned_join_date`

Vacant Planning (`employee_id` null) does not increase headcount. Soft-deleted Planning and cancelled assignments are excluded. Linked Planning + Assignment count once (assignment wins).

### Leave / sign-off precedence

1. P4 `actual_end_at`
2. Assignment `planned_signoff_at`
3. P4 `planned_end_at`
4. Planning `planned_leave_date`
5. Open-ended (remains onboard through the horizon)

Planned Sign-Off is forecast-only; it never completes P4, creates Sea Service, or payroll actuals.

### Same-day ordering

Company-local calendar days. Event **display** order on a shared date is join before sign-off. Min/max/gap/overlap are calculated from the **net end-of-day count** after all events for that date are applied, so a one-for-one handover does not create false overlap.

### Actual vs projected counts at range start

| Field | Meaning |
|-------|---------|
| `actual_onboard_at_start` | Only actual P4 intervals (`actual_start_at` ≤ `from`, and `actual_end_at` null or ≥ `from`). Forecast leave dates never reduce this count. |
| `projected_count_at_start` | Forecast coverage at `from` using actual end when present, otherwise planned sign-off / planned end / Planning leave |
| `starting_count` | Compatibility alias of `projected_count_at_start` |

Overdue Planning rows and pre-P4 Draft/planned assignments are **not** actual onboard. They may still contribute to `projected_count_at_start` when their resolved join ≤ `from` and leave is open or ≥ `from`. An open actual P4 with a planned sign-off already before `from` remains `actual_onboard_at_start = 1` and `projected_count_at_start = 0`. Planned Sign-Off is never Actual Disembarkation.

### Repeatable P4 phases

Each On Vessel phase with `actual_start_at` becomes its own projection segment (ordered by `sequence`). Assignment/Planning forecast dates are used only when they do not duplicate an actual P4 segment (typically Draft/Active with no actual P4 yet).

### Completed / P5 / P6

Completed assignments contribute historical actual P4 intervals only — never stale planned joins or Planning-driven future joins. Active P5/P6 may contribute historical P4 intervals but never projected fallback joins. Cancelled assignments remain excluded.

### Tenant-safe relations

Trusted `companyId` is enforced independently on Vessel Manning, assignments, phases, linked Planning, and employees. Malformed cross-company relations are omitted and their IDs are not exposed on events.

### Relief

Uses Phase 2A Planning relief links. Late relief → gap between source leave and relief join; early relief → overlap/excess. Historical P5/P6 / cancelled / completed linked relief follow assignment P4 history and status exclusions, not readiness labels alone.

### Summary overlap

`summary.overlap_positions` counts rows where `has_overlap` is true (not only when primary status is `overlap`).

## Phase 2B.2 — Projected Manning Engine & Surface Integration

There is **no standalone Projected Manning page**. Projected manning is an internal calculation engine (`CrewProjectedManningQuery`) that drives operational insights across Crew Operations:

- **Vessels** (`/organization/vessels`) configure company-owned vessels and required vessel/rank headcount (manning) on the vessel show page. Bulk maintenance uses **Export CSV** or the Import dialog’s **Download vessel data** (the same current-company CSV with stable `vessel_id` values), then edit and **Import CSV**: rows with `vessel_id` update that company-owned vessel; blank `vessel_id` creates a new vessel; vessels omitted from the file are unchanged; CSV import never deletes. `Vessel.client_id` is the vessel’s current/default operational Client; historical `CrewAssignment.client_id` and `EmployeeSeaService.client_id` snapshots are not rewritten by vessel import.
- **Projected Manning Engine** (`CrewProjectedManningQuery`) calculates required vs actual/projected crew, gaps, and overlaps.
- **Crew Planning** (`/organization/crew-planning`) visually displays projected gap and overlap overlays on the Gantt timeline.
- **Crew Operations Overview** (`/organization/crew-operations`) surfaces projected risk analytics and action items, linking directly into Crew Planning.
- **Operational Alerts** (`ProjectedManningGap`) detect projected shortfall conditions and resolve URLs to Crew Planning (with Overview/Vessels fallbacks).

### Vessel Manning Health

Vessel Show (`/organization/vessels/{vessel}`) presents a read-only **Manning Health** section. Vessel Index can show the same compact status. There is no manning-health table, snapshot job, or second projection engine.

| Status | Meaning |
|--------|---------|
| `healthy` | Requirements exist, no current shortage, no projected shortage in the next **30 days** |
| `at_risk` | Adequately manned now; `CrewProjectedManningQuery` shows a shortfall within 30 days |
| `critical` | Current onboard (active P4) is already below required. Current shortage outranks future shortage |
| `not_configured` | No valid `VesselManning` rows. Never shown as Healthy because requirements are missing |

Overlap/excess remains supporting copy (for example `+1 temporary overlap`). It is not a primary health state.

**Sources (unchanged):** `VesselManning` (required), `CurrentOnboardCrewQuery` (actual onboard), `CrewProjectedManningQuery` (projected coverage), `CrewReliefReadinessResolver` (relief labels). Planned Sign-Off is forecast only and does not disembark anyone. Planned relief is not actual onboard until active P4. Relief status and Mobilisation Readiness are advisory and never override projected coverage or block movement.

Health is derived at read time. Viewing it is not audited. Actions link into existing Relief Desk, Crew Planning, Current Crew, and Edit Manning workflows.

Compact index health reuses one company-level projection plus one onboard query — not one projection per vessel. Optional server-side `health=` filter (`critical`, `at_risk`, `healthy`, `not_configured`) sits beside Configured/Pending Manning filters; those remain configuration completeness, not coverage.

### Deferred (still later)

- Notifications / escalations
- Phase 3 notifications

## Phase 2B.3A — Daily Operations Dashboard + projected manning risk integration

Crew Operations landing page (`/organization/crew-operations`) is an **operational cockpit**, not an analytics board.

### Sections

1. **Header / quick actions** — Current Crew, Planning (permission-gated; Settings is not a primary daily action).
2. **Daily Pulse** (max 4) — Onboard Now (actual P4), Joins Next 7 Days, Sign-offs Next 7 Days (+ overdue secondary), Coverage Risks (`current now · upcoming projected`).
3. **Action Required** — bounded (≤10) urgency-ordered items: current manning gap → overdue sign-off → due today no relief → critical relief → imminent not-ready relief → projected future gap → overdue corrections → needs update → over-home.
4. **Next 7 Days** — compact join/sign-off day list + Open Crew Planning.
5. **Manning & Relief Risks** — bounded mixed list with explicit Actual / Projected / Relief kinds + Open Crew Planning.

### Projected manning

- Still calculated only via `CrewProjectedManningQuery` (company-local today → +30 days).
- `projected_manning` is `null` without `crew_operations.vessel_manning.view`.
- Used for Coverage Risks upcoming, Action Required future-gap rows, and projected risk rows — not a large KPI card on the landing page.
- Projected manning calculations surface through Crew Planning overlays, Overview risks, and operational alert delivery; there is no standalone page.

### Removed from landing presentation

Deployment trends chart, phase-status grid, crew pool, recent activity, standalone movement-corrections card, Tour/Relief KPI grids, standalone Manning Gaps card, large Projected Manning card. Underlying routes/queries/features remain elsewhere.

### Deferred (still later)

- Notifications / escalations

## Phase 2B.3B — Crew Planning Gantt Projection Overlays

Crew Planning (`/organization/crew-planning`) layers read-only projected coverage onto the existing Gantt.

### Rules

- Projection comes only from `CrewProjectedManningQuery` using Planning’s exact `from` / `to` / `vessel_id` / `rank_id` (no separate 30/60/90 horizon).
- Compact payload via `CrewPlanningProjectionPresenter` (`projection` prop). Events / employee IDs / assignment IDs are omitted.
- Extra permission: `can.projection` = `crew_operations.vessel_manning.view`. Without it Planning works normally and `projection` is `null`.
- Gantt row catalog merges Planning rows with company-scoped Vessel Manning positions from projection so empty ranks with gaps still appear. Authoritative `required_count` comes from Vessel Manning when projection is present. No Planning records are created merely to show rows.
- Frontend draws only exception periods (`gap > 0` red, `excess > 0` amber) behind Planning bars. Covered periods stay quiet. Optional local “Show coverage” toggle (default on when `can.projection`).
- Gap band click (when `can.create`) opens the existing Assign Crew Sheet via `openCreateForRow` with vessel/rank and gap `from` as join date. DnD / row-click / edit / delete / zoom remain primary.
- No movement mutations from projection.

### Deferred (still later)

- Phase 3 notifications / escalations

## Phase 2C.1 — Transfer / Redeployment Tour Hardening

Hardens existing `TransferVessel` / `Redeploy` without redesigning Current Crew or Planning.

| Rule | Behaviour |
|------|-----------|
| Linked assignment | Destination is a new `CrewAssignment` with `previous_assignment_id`; source vessel/rank/P4 history is preserved |
| Direct P4 transfer / redeploy | Fresh Tour snapshot via `CrewTourOfDutyResolver` + `CrewJoinVesselSignoffApplier` (destination rank + `occurred_at`) |
| Pre-P4 redeploy | No Tour snapshot; Join Vessel applies Tour later |
| Sea Service | Source P4 completion syncs once; destination Sea Service waits until destination P4 completes |
| Planned vs actual | Only actual `occurred_at` handoff completes source P4 |
| Rollback | Tour/sign-off validation runs before source mutation; failures leave source active |

### Deferred (still later)

- Phase 3B+ delivery / escalations (in-app, Web Push); Phase 3C email

## Phase 2C.2 — Transfer / Redeploy Tour UI Alignment

Movement dialogs reuse shared Tour / Planned Sign-Off controls (`TourSignoffFields`) for Join Vessel, Transfer Vessel, and Redeploy starting at P4.

| Surface | Behaviour |
|---------|-----------|
| Transfer Vessel | Destination-rank Tour default (`tour_of_duty` when resolved); no `existing_plan` |
| Redeploy P4 | Same Tour / sign-off controls as Transfer |
| Redeploy P0–P3 | Tour fields hidden and excluded from submit; optional forecast sign-off only |
| Payload | Empty `tour_of_duty_days` omitted; non-manual choices drop stale date/reason |

Backend Tour resolution remains authoritative.

### Deferred (still later)

- Phase 3B+ delivery (in-app bell, Web Push, email)

## Phase 3A — Crew Operational Alerts Foundation + Company Notification Settings

Crew Operations → Settings gains a **Notifications** card. Defaults **OFF** so existing companies are not notified after migration.

| Concern | Behaviour |
|---------|-----------|
| Master switch | Company-level Crew Notifications ON/OFF (default OFF) |
| Recipients | Explicit selected active company users with active membership only — no role-based recipient config |
| Alert types | Independent toggles for overdue, no-relief, relief-not-ready, current gap, projected gap |
| Delivery | No separate company In-app / Browser Push / Email toggles |
| Persistence | `crew_operational_alerts` with unique `(company_id, dedupe_key)` and active/resolved lifecycle |
| Detection | `DetectCrewOperationalAlerts` uses Tour / Relief / Manning / Projected queries — not Action Required |
| Reconciliation | `ReconcileCrewOperationalAlerts` + `crew:reconcile-operational-alerts` every 10 minutes (`withoutOverlapping`) |
| Tenancy | Trusted `current_company_id`; per-company safe iteration |

One row per company condition. History is preserved via `detected_at` (first detection), `last_detected_at`, `resolved_at`, `status`, and activity log. When a resolved condition returns, the same row is reactivated.

## Phase 3B — Unified Notification Bell + Crew Browser Push + Escalation

Crew operational alerts appear in the existing notification bell alongside Announcements.

| Concern | Behaviour |
|---------|-----------|
| Unified feed | Server-backed `BuildUnifiedNotificationFeed` merges AnnouncementRecipient + CrewOperationalAlertRecipient |
| Recipient/read state | `crew_operational_alert_recipients` with unique `(alert_id, user_id)` and per-user `read_at` |
| Unread badge | Combined announcement + active Crew unread count |
| Browser Push | Extension of in-app Crew notifications; uses the user's existing device subscription preference. **No company Browser Push toggle** |
| Push privacy | Generic lock-screen copy only: “Crew Operations requires attention. Open OMS-HRM to review.” |
| Push dedupe | `crew_operational_alert_push_deliveries` unique on `(alert_id, user_id, notification_version)` |
| Version bumps | New alert, reactivation, and meaningful severity escalation increment `notification_version` and may push again |
| Escalation | Sign-off/relief windows tighten severity (8–14 warning, ≤7 critical); current manning critical; projected gap warning |
| Links | Permission-safe URLs to Current Crew / assignment, Vessels, or Projected Manning |
| Jobs | `DeliverCrewOperationalAlertWebPushJob` afterCommit; re-checks company, membership, selection, alert activity, subscriptions |

### Deferred (still later)

- Further escalation / digest workflows beyond Phase 3C

## Phase 3C — Operational Alert Email Delivery + Retry Hardening

Email is an automatic extension of Crew operational notifications. There is **no company Email ON/OFF toggle** and no separate Crew email settings.

| Concern | Behaviour |
|---------|-----------|
| When emailed | Same meaningful `notification_version` events as Web Push: new alert, reactivation, severity escalation |
| Not emailed | Unchanged reconciliation, `last_detected_at` refresh, resolved alerts |
| Recipients | Selected active company members with a usable email address |
| SMTP | Existing application `MailSettingsService` (settings over env). No second SMTP system |
| Ledger | `crew_operational_alert_email_deliveries` unique on `(alert_id, user_id, notification_version)` |
| Jobs | `DeliverCrewOperationalAlertEmailJob` after successful queue handoff (`dispatched_at`); re-checks company, membership, selection, alert activity/version, email, SMTP |
| Retry | Bounded tries/backoff; transport failures keep queued until exhausted → `email_transport_exhausted`. Successful SMTP/Web Push handoff is not retried when only ledger persist fails |
| Privacy | Subject: “Crew Operations requires attention.” No employee/vessel/rank/assignment detail |
| Links | Permission-safe CTA via `ResolveCrewOperationalAlertUrl`; omit CTA when unauthorized |
| Independence | Email and Web Push ledgers/queues are independent |

See [Crew operational alerts email delivery](../crew-operational-alerts-email.md).

## Sea service

Requires P4 with `actual_start_at`, `actual_end_at`, plus assignment vessel/rank/employee. Linked by unique `employee_sea_services.crew_assignment_phase_id`.

## Status / dashboard / manning / attention

- `CrewAssignmentStatusResolver` maps current phase → operational status.
- Dashboard counts use latest relevant assignment per employee, plus Tour of Duty sign-off buckets (within 30/14/7 days, due today, overdue, missing tour/sign-off).
- Onboard manning = active assignment + active P4 on vessel. Planned sign-off does not remove onboard crew.
- Attention rules live in `CrewMovementAttentionQuery` (stale draft/phase, overdue planned join/sign-off, missing vessel/rank, tour due/overdue/missing).

## Assignment numbers

`crew_assignment_sequences` per company/year, locked increment → `CA-{YEAR}-{000001}`.

## Timezone convention

- Interpret user-entered timestamps in the **company timezone**.
- Persist using the application/database datetime convention.
- Display dates consistently as date strings from presenters (`toDateString()` for calendar fields).
- Planned Sign-Off is never treated as Actual Disembarkation.

## Create Page Behaviour

`organization/crew/create` renders employee operational status intelligence immediately after the user selects an employee from the form. The status is batch-resolved on the server by `CrewAssignmentStatusResolver` and injected into `form_options.employee_status_by_employee` (keyed by employee ID integer).

### Status visibility and authorization scoping

| User has `crew_operations.assignments.view` | Fields exposed |
|---|---|
| Yes | All fields: `assignment_id`, `assignment_no`, `vessel_name`, `current_vessel`, `since`, `days_in_phase`, `planned_next_date`, `warning`, `in_home_days` |
| No (create only) | Only: `status`, `label`, `has_active_assignment`. All other fields are `null`. |

Backend authorization remains authoritative — the scoping on the create page is a defence-in-depth measure, not the primary access control.

### Active assignment UI blocking

- `has_active_assignment` is always included in the payload regardless of view permission.
- It is `true` when the employee has an **Active** assignment, including **Active P0**. Draft P0 remains `false`.
- When `has_active_assignment = true` **and** the Transfer Vessel intercept is not active, Start Assignment and Save as Draft are **disabled**.
- The conflict UI shows: "This employee already has an active Crew Assignment."
- If the viewer has view permission (`assignment_no` is non-null), a "Continue [CA-XXXXXX]" button is shown, opening the existing assignment in a new tab.
- Users with create permission but without `crew_operations.movements.perform` can still Save as Draft. Start Assignment is hidden and explained.

### P4 On Vessel — Transfer Vessel intercept

When the selected employee is P4 On Vessel **and** the destination vessel differs from the current vessel, the create page surfaces the Transfer Vessel dialog instead of blocking the submit button. The Transfer Vessel movement creates a new Active assignment and completes the old one atomically in a single transaction.

This intercept is powered by `ActiveOnVesselAssignmentFinder`, which provides `form_options.active_on_vessel_by_employee` (also keyed by employee ID). `can_transfer` within each entry reflects the user's movement-perform permission.

### Operational timestamps and timezone

All status timestamps (`since`, `actual_start_at`) are rendered in the **company timezone** using `formatDisplayDateTimeInTimezone(value, companyTimezone)`. The company's IANA timezone string is exposed as `form_options.company_timezone` and derived from `CompanyTimezone::forCompanyId($companyId)`.

### Generic backend error display

`form.errors.error` is rendered near the submit button area. The `error` validation key is generated by `CrewMovementException → ValidationException` conversions in the movement pipeline (e.g. if the active-assignment guard triggers at the backend despite the UI block).

## Master data

Global (no `company_id`): ranks, vessels, clients, company visa types. Filter by `is_active` when present.

Tenant-scoped: employees (`company_id`, `employee_no`), crew assignments, phases, sequences, crew rank policies.

Rank CSV import supports `name,is_active,max_tour_of_duty_days` (blank Tour cell preserves existing value).

## Production verification commands

```bash
php artisan migrate
php artisan db:seed --class=PermissionsSeeder
composer ci:check
npm run lint:check
npm run format:check
npm run types:check
npm run build
php artisan test --compact --filter=Crew
```

See also `docs/runbooks/crew-movement-qa.md`.

