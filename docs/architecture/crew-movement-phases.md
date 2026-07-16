# Crew Movement Phases

CrewAssignment is the **single source of truth** for crew movement lifecycle. This document describes the domain model and service behaviour.

**EmployeeDeployment has been removed.** CrewAssignment now drives sea service sync, employee crew status, manning queries, and planning integration.

## 1. Purpose of `CrewAssignment`

`CrewAssignment` represents **one complete mobilisation cycle** for a crew employee within a company: from pre-mobilisation through vessel service to home or redeployment.

It is the parent record for ordered, repeatable phase occurrences and the home for movement actions (Phase 1+).

## 2. Assignment vs phase vs planning vs sea service

| Concept | Role |
|---------|------|
| **CrewAssignment** | One mobilisation cycle (P0–P6 lifecycle container). |
| **CrewAssignmentPhase** | One occurrence of a phase on that cycle (ordered by `sequence`). |
| **EmployeeDeployment** | Current operational / compatibility record; drives sea-service sync and much of today’s UI. |
| **CrewPlanningAssignment** | Gantt / planning board slot (planned join/leave). |
| **EmployeeSeaService** | Historical sea time derived from completed vessel tours (today still synced from deployments). |

Domain tree:

```text
CrewAssignment
├── CrewAssignmentPhase
├── Employee
├── Rank
├── Client
├── Vessel
├── CompanyVisaType
├── Previous CrewAssignment
└── CrewPlanningAssignment
```

## 3. P0–P6 definitions

| Code | Label | Intent |
|------|-------|--------|
| **P0** | Pre-Mobilisation | Approvals, documents, readiness before travel. |
| **P1** | Travel In | Inbound travel / arrival toward mobilisation location. |
| **P2A** | Join Standby | Waiting / standby before join (or between training loops). |
| **P2B** | Training | Training course occurrence (repeatable). |
| **P3** | Ready to Join | Cleared and waiting to join vessel. |
| **P4** | On Vessel | Active vessel tour. |
| **P5** | Demobilisation Standby | Post-sign-off standby before home travel. |
| **P6** | Home / Redeployment | Travelled home or end of cycle pending redeploy/transfer link. |

## 4. Planned dates versus actual dates

- Assignment-level planned fields (`planned_join_at`, `planned_signoff_at`, `planned_travel_at`) hold the **intended** mobilisation plan.
- Phase-level `planned_start_at` / `planned_end_at` hold the plan for that occurrence.
- Phase-level `actual_start_at` / `actual_end_at` hold what really happened.
- **Plan sign-off** updates planned fields only; it does not complete P4.
- **Confirm disembarkation** sets P4 `actual_end_at` and never overwrites `planned_signoff_at`.
- Join vessel does **not** require an actual disembarkation date.

## 5. Repeatable phase behavior

`CrewAssignmentPhase` is **one occurrence** of a phase. The same `phase_code` may appear more than once on one assignment.

Supported loop example:

```text
P2A → P2B → P2A → P3
```

There is **no** unique constraint on `(crew_assignment_id, phase_code)`. Order is determined by `sequence`.

**P2B training and P3 readiness cannot be inferred reliably from legacy `EmployeeDeployment` date fields.**

## 6. Same-assignment versus linked-assignment transitions

`App\Support\CrewMovements\CrewMovementTransitionMap`:

- `allowedNextPhases()` / `canTransitionWithinAssignment()` — normal transitions **inside** one assignment. **P5/P6 do not return P0.**
- `canStartLinkedAssignment()` — P5 or P6 may start a **new** linked assignment at P0 (transfer/redeploy). Execution is not implemented in this phase.
- Deprecated `canTransition()` mirrors within-assignment rules only.

Same-assignment map:

```text
P0 → P1
P1 → P2A | P3
P2A → P2B | P3 | P4
P2B → P2A | P3
P3 → P4
P4 → P5 | P6
P5 → P6
```

## 7. Invariant rules

`CrewAssignmentInvariantGuard` rejects (does not repair):

- Company mismatches across employee, phases, current phase, previous assignment, linked deployment/planning
- Employee mismatches on previous assignment and linked records
- Current phase missing, soft-deleted, wrong assignment/company, or wrong status for assignment status
  - Draft: current phase may be `planned` or `active`
  - Active: current phase must be `active`
- Duplicate sequences, invalid planned/actual ranges, more than one active phase
- Completed/cancelled without `closed_at`; active without `started_at`

Failures throw `App\Exceptions\CrewMovementException` with a user-safe message and `errorCode`.

## 8. Movement service transaction behaviour

`CrewMovementService`:

1. Opens a DB transaction
2. Loads the assignment with `lockForUpdate()` scoped by `company_id`
3. Validates invariants
4. Validates the action
5. Completes/updates the current phase and creates the next phase when required (atomically)
6. Updates assignment status / current phase / actor fields
7. Revalidates invariants
8. Commits (or rolls back on any failure)

Sequence for new phases: `max(sequence including soft-deleted) + 1` while the assignment row is locked.

**Draft convention:** create P0 as `planned` and set `current_phase_id` to that P0.

**Close convention:** `current_phase_id` remains the completed P6.

**Sea service sync:** `SyncSeaServiceFromCrewAssignment::syncFromPhase()` creates/updates `EmployeeSeaService` when P4 is completed with both `actual_start_at` and `actual_end_at`. Links via `crew_assignment_phase_id`.

**Planning integration:** `CreateCrewAssignmentFromPlanning::handle()` creates draft assignment from planning. Updates `CrewPlanningAssignment.crew_assignment_id` for linkage. Idempotent.

## 9. Action semantics (Phase 1)

Implemented: `approve_mobilisation`, `record_arrival`, `start_join_standby`, `send_to_training`, `complete_training`, `mark_ready`, `join_vessel`, `plan_signoff`, `confirm_disembarkation`, `start_demob_standby`, `travel_home`, `close_assignment`, `cancel_assignment`.

Not implemented (clear domain error): `transfer_vessel`, `redeploy`, `correct_movement`.

Operational timestamps use payload `occurred_at` parsed in the **company timezone** (never silent server-now when provided).

Cancel: allowed for draft/active **before** active P4; requires reason.

## 10. Assignment numbers

Assignment numbers: `CA-{year}-{000001}` via sequential allocation per company using `crew_assignment_sequences` table with row-level locking (`CrewAssignmentNumberGenerator`).

## 11. Legacy removal

**EmployeeDeployment and all related code has been removed:**
- `employee_deployments` table dropped
- `EmployeeDeployment` model deleted
- `LegacyDeploymentBackfillService` removed
- `php artisan crew-movements:backfill` command removed
- Crew Deployments board UI routes removed (`/organization/crew-deployments`)

No backfill is available. CrewAssignment is the only crew movement record going forward.

## 12. Transfer and redeployment concept

Transfer/redeploy should create a **new** `CrewAssignment` linked via `previous_assignment_id`. Phase 1 stores the vocabulary and linked-start helpers only; execution is out of scope.

## 13. Company-scoping requirements

- Every assignment and phase row has `company_id`.
- Service mutations lock and scope by company.
- `assignment_no` is unique **per company** (enforced at DB level).
- Planning links via `CrewPlanningAssignment.crew_assignment_id` and `relieves_crew_assignment_id` (nullable, FK to `crew_assignments`).
- Employee FK uses **restrict on delete** so movement history is preserved.

## 14. Audit requirements

Models use `LogsActivityWithCompany`. Important assignment and phase attributes are logged dirty-only.

## 15. Future payroll, billing, cost, and margin integration

`details` JSON on phases is reserved for later cost/billing payloads. This phase does **not** calculate payroll, client billing, or invoices.

## 16. Current integration status

- ✅ Employee crew status uses `CrewAssignmentStatusResolver::forEmployee()`
- ✅ Sea service sync from completed P4 phases via `SyncSeaServiceFromCrewAssignment`
- ✅ Crew planning creates draft assignments via `CreateCrewAssignmentFromPlanning`
- ✅ Manning gap queries count active P4 phases via `CrewOperationsManningGapQuery`
- ✅ Deployment trends and analytics from assignments
- ⏳ Crew Movement CRUD UI (assignments and phases)
- ⏳ Transfer / redeploy / correction execution
- ⏳ Payroll, billing, hotel/training/flight/visa costs, margin, invoices
