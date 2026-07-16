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

## Unsupported (future)

```text
transfer_vessel
redeploy
correct_movement
```

These return a clear `CrewMovementException` (`action_not_implemented`).

## Permissions

Use Spatie permission names:

```text
crew_operations.assignments.view
crew_operations.assignments.create
crew_operations.assignments.update
crew_operations.movements.perform
crew_operations.assignments.cancel
audit.view
```

Legacy `crew_operations.deployments.*` permissions are removed and migrated onto assignment permissions.

## Movement service

`CrewMovementService` runs every create/action in a company-scoped transaction with `lockForUpdate()`, invariant checks, and atomic phase updates. Completed P4 (`actual_end_at` set) syncs sea service via `SyncSeaServiceFromCrewAssignment` in the same transaction.

## Planning

`CreateCrewAssignmentFromPlanning` creates a draft assignment (`source = crew_planning`) and sets `crew_planning_assignments.crew_assignment_id`. Relief linking uses `relieves_crew_assignment_id`. Gantt payload uses `is_assigned` when a planning row is linked to a CrewAssignment.

## Sea service

Requires P4 with `actual_start_at`, `actual_end_at`, plus assignment vessel/rank/employee. Linked by unique `employee_sea_services.crew_assignment_phase_id`.

## Status / dashboard / manning / attention

- `CrewAssignmentStatusResolver` maps current phase → operational status.
- Dashboard counts use latest relevant assignment per employee.
- Onboard manning = active assignment + active P4 on vessel. Planned sign-off does not remove onboard crew.
- Attention rules live in `CrewMovementAttentionQuery` (stale draft/phase, overdue planned join/sign-off, missing vessel/rank).

## Assignment numbers

`crew_assignment_sequences` per company/year, locked increment → `CA-{YEAR}-{000001}`.

## Timezone convention

- Interpret user-entered timestamps in the **company timezone**.
- Persist using the application/database datetime convention.
- Display dates consistently as date strings from presenters (`toDateString()` for calendar fields).
- Planned Sign-Off is never treated as Actual Disembarkation.

## Master data

Global (no `company_id`): ranks, vessels, clients, company visa types. Filter by `is_active` when present.

Tenant-scoped: employees (`company_id`, `employee_no`), crew assignments, phases, sequences.

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
