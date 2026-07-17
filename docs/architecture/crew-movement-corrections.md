# Crew Movement Corrections

Approved corrections are the only path that changes recorded movement fields after the fact. Pending, rejected, and cancelled requests never mutate official `CrewAssignment` / `CrewAssignmentPhase` data.

## Flow

```text
Request correction
    → pending review
    → approve (atomic apply + planning sync + sea-service sync)
    or reject / cancel (no official data change)
```

## What can be corrected

| Target | Fields |
|--------|--------|
| Recorded phases (`active` / `completed` with `actual_start_at`) | `actual_start_at`, `remarks` |
| Completed phases | also `actual_end_at` |
| Training | also `details.provider`, `details.course` |
| On Vessel (P4) | also assignment `vessel_id`, `rank_id`, `client_id`, `company_visa_type_id` |

Derived updates on approve:

- P1 start → assignment `started_at`
- Completed P6 end → assignment `closed_at`

## Hard rules

- Originals are always read from the database at request time
- One pending correction per phase
- No nulling existing values; no topology changes (`phase_code`, `status`, `sequence`, `current_phase_id`, `employee_id`)
- Active phases stay open-ended (cannot add `actual_end_at` via correction)
- Neighbor-phase boundary checks and company-timezone parsing
- Self-approval denied unless `crew_operations.corrections.override`
- Operational phase status is never flipped to `corrected` for badges — badges come from correction relations

## Permissions

| Permission | Granted from |
|------------|--------------|
| `crew_operations.corrections.view` | roles with `crew_operations.assignments.view` |
| `crew_operations.corrections.request` | roles with `crew_operations.movements.perform` |
| `crew_operations.corrections.approve` | roles with `crew_operations.assignments.update` |
| `crew_operations.corrections.override` | roles with `roles.update` (Owner/admin) |

## Approval lock order

1. Assignment
2. Correction
3. Target phase
4. Linked planning assignment (when present)
5. Linked sea service rows (when present)

Then: stale-original conflict check → validate → apply → invariants → planning sync → sea-service sync (completed P4 only; reject if unsyncable) → mark approved.

Notification failures after commit are reported and never roll back approval.

## UI entry points

- Assignment show: **Request Correction** (separate from movement actions)
- Crew Operations → **Movement Corrections**
- Crew Operations overview: pending corrections attention when the user can approve

## Non-goals

- No `EmployeeDeployment` restoration
- No vessel transfer / redeployment via corrections
- No immediate apply via `CrewMovementAction::CorrectMovement`
- Pending proposals never affect reports’ official dates
