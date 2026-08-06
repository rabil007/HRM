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

## Domain model

| Concept | Role |
|------|------|
| **CrewPlanningAssignment** | Planned join/leave on the Gantt board; may convert into a draft assignment. |
| **CrewAssignment** | One mobilisation cycle (P0–P6). |
| **CrewAssignmentPhase** | Ordered occurrence of a phase on that cycle. |
| **EmployeeSeaService** | Historical sea time created from completed P4 phases. |
| **CrewRankPolicy** | Company-scoped Tour of Duty override per rank (does not mutate global Rank). |

## P0–P6

| Code | Label |
|------|-------|
| P0 | Pre-Mobilisation |
| P1 | Travel In |
| P2A | Join Standby |
| P2B | Training |
| P3 | Ready to Join |
| P4 | On Vessel |
| P5 | Demobilisation Standby |
| P6 | Home / Redeployment |

## Tour of Duty (Phase 1)

When Join Vessel creates active P4, the system may resolve a Tour of Duty and suggest Planned Sign-Off.

### Resolution precedence

1. Assignment-specific override entered during Join Vessel (`assignment_override`)
2. Active company rank policy (`company_rank_policy`)
3. Global `ranks.max_tour_of_duty_days` (`global_rank_default`)
4. No automatic calculation

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

After P4 join, `crew_assignments.tour_of_duty_days` and `tour_of_duty_source` are snapshotted. Later changes to Rank or company policy do **not** rewrite existing assignments. New joins use the latest rules.

### Planned versus actual

- Planned Sign-Off is an expected date only (`planned_signoff_at` / P4 `planned_end_at`).
- A generated Planned Sign-Off must **never** complete P4, disembark the employee, close the assignment, create payroll days, or create Sea Service.
- Actual disembarkation remains a separate `confirm_disembarkation` action.

### Join Vessel sign-off choices

| Choice | Behaviour |
|--------|-----------|
| `tour_of_duty` | Use calculated suggestion |
| `existing_plan` | Keep existing Planning/assignment planned date (never silently overwritten) |
| `manual_override` | Enter another date; reason required |

If no Tour exists and no Planned Sign-Off is entered, join still succeeds and attention warnings surface `missing_tour_of_duty` / `missing_planned_signoff`.

### Company rank policies

Manage under Crew Operations → Rank Tour Policies (`crew_operations.rank_policies.view|update`). Clearing an override soft-deletes the policy and sets `is_active = false`. Re-creating the same company + rank restores the soft-deleted row and updates `tour_of_duty_days` (unique `company_id + rank_id` is preserved). Cross-company deleted rows are never restored.

### Permission mapping for Rank Tour Policies

Roles are company-scoped and managed via Organization → Roles. `PermissionsSeeder` grants:

| Existing role capability | Granted |
|--------------------------|---------|
| `crew_operations.planning.update` | `rank_policies.view` + `rank_policies.update` |
| `crew_operations.planning.view` or `overview.view` (without planning.update) | `rank_policies.view` only |
| Neither | unchanged |

The seeded `Owner` role continues to receive all permissions when `AdminSeeder` runs (`syncPermissions` of the full catalog).

### Correction recalculation

When an approved correction changes P4 `actual_start_at` and `planned_signoff_source` is `tour_of_duty`, Planned Sign-Off is recalculated from the **snapshotted** tour days and written to:

1. `crew_assignments.planned_signoff_at`
2. P4 `crew_assignment_phases.planned_end_at`
3. Linked `crew_planning_assignments.planned_leave_date` (via `SyncPlanningAssignmentFromCrewAssignment` in the same approval transaction)

Manual / existing-plan sources are preserved. Pending corrections do not mutate official dates. A failure during approval rolls back assignment, phase, planning, and correction status together.

### Transfer / redeployment (Phase 1 limitation)

Vessel transfer and redeployment do not yet re-resolve Tour of Duty for the linked destination assignment beyond existing movement payload fields. Destination Planned Sign-Off continues to follow the existing transfer/redeploy payload rules.

### Notifications deferred

Email, browser Web Push, in-app notification feeds, escalation, and Announcement records for Tour of Duty are **not** implemented in Phase 1.

## Supported movement actions

| Action | Typical from phase |
|--------|--------------------|
| `approve_mobilisation` | P0 |
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
| `cancel_assignment` | Draft/Active → Cancelled |

## Linked assignment actions

### Transfer Vessel (`transfer_vessel`)

Available from Active P4 On Vessel. Completes the source P4 and assignment at `occurred_at`, syncs sea service and planning for the source, then creates a linked Active assignment (`previous_assignment_id`, `source = vessel_transfer`) that starts directly in active P4 on the destination vessel. Destination vessel must start blank in the form, must differ from the source, and is required. Rank/client may default from the current assignment. No artificial P5/P6/P0–P3 phases are created. The movement controller redirects to the new assignment.

### Redeploy (`redeploy`)

Available from Active P5 or P6. Completes the source phase and assignment, then creates a linked assignment (`source = redeployment`) starting only at the chosen real phase: P0 (Draft + planned; vessel optional; planned sign-off cleared when not applicable), or P1 / P2A / P3 / P4 (Active; vessel optional except P4 requires vessel and rank). Same or different vessel/client is allowed. Hidden stale destination fields must not be submitted for P0. Earlier phases are never invented.

### Still unsupported as an immediate movement action

```text
correct_movement
```

Movement field corrections use a dedicated approval workflow instead of `correct_movement`. See [crew-movement-corrections.md](./crew-movement-corrections.md).

## Permissions

Use Spatie permission names:

```text
crew_operations.assignments.view
crew_operations.assignments.create
crew_operations.assignments.update
crew_operations.movements.perform
crew_operations.assignments.cancel
crew_operations.corrections.view
crew_operations.corrections.request
crew_operations.corrections.approve
crew_operations.corrections.override
crew_operations.rank_policies.view
crew_operations.rank_policies.update
audit.view
```

Legacy `crew_operations.deployments.*` permissions are removed and migrated onto assignment permissions.

## Movement service

`CrewMovementService` runs every create/action in a company-scoped transaction with `lockForUpdate()`, invariant checks, and atomic phase updates. Completed P4 (`actual_end_at` set) syncs sea service via `SeaServiceSyncService` in the same transaction.

Tour resolution uses `CrewTourOfDutyResolver` / `CrewTourOfDutyCalculator`. Progress and status buckets use `CrewTourProgress` / `CrewTourStatusQuery`.

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
