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
- Crew Operations overview: compact pending/overdue summary for users with correction view permission

## Pending SLA and ageing

`CrewMovementCorrectionSla` calculates pending age from `requested_at`, falling back to `created_at` for legacy rows. Age is the number of completed calendar days between the request date and today in the active company timezone. The browser clock is not used and derived values are not stored.

| Completed days | SLA |
|----------------|-----|
| 0–1 | Normal |
| 2–3 | Attention |
| 4+ | Overdue |

Approved, rejected, and cancelled corrections have `not_applicable` SLA status. Thresholds are centralized in the SLA class so they can be made configurable later without changing presenters or queries.

The correction list uses SQL cutoffs derived from the same SLA thresholds for filtering, priority ordering, and aggregate counts. Pending rows sort overdue → attention → normal; non-pending decisions follow newest first. The compound `company_id`, `status`, `requested_at` index supports company-scoped pending lookups.

## Page responsibilities

**Crew Operations overview → high-level operational attention only**

- One compact correction summary
- Pending count
- Overdue count
- Link to Movement Corrections

**Movement Corrections page → detailed correction management and approval**

- SLA age, status, filters, and priority order
- Requester, assignment, phase, and field counts
- Original/proposed/live comparisons
- Approval, rejection, cancellation, conflicts, and decision history

Detailed correction rows, values, actors, filters, history, and charts are intentionally excluded from Crew Operations. This keeps the overview focused on onboard crew, upcoming joins, sign-offs, manning gaps, movement attention, and operational phase counts.

## Non-goals

- No `EmployeeDeployment` restoration
- No vessel transfer / redeployment via corrections
- No immediate apply via `CrewMovementAction::CorrectMovement`
- Pending proposals never affect reports’ official dates
