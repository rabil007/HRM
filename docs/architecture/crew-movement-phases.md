# Crew Movement Phases

Phase 0 foundation for the reusable crew mobilisation and movement lifecycle. This document describes the intended domain model. It does not replace the live Crew Deployments module.

## 1. Purpose of `CrewAssignment`

`CrewAssignment` represents **one complete mobilisation cycle** for a crew employee within a company: from pre-mobilisation through vessel service to home or redeployment.

It is the parent record for ordered, repeatable phase occurrences and the future home for movement actions, transfers, and redeployments.

## 2. Assignment vs phase vs deployment vs planning vs sea service

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
├── EmployeeDeployment
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
- Future movement services update actuals without erasing planned values (corrections use phase status `corrected` where needed).

## 5. Repeatable phase behavior

`CrewAssignmentPhase` is **one occurrence** of a phase. The same `phase_code` may appear more than once on one assignment.

Supported loop example:

```text
P2A → P2B → P2A → P3
```

There is **no** unique constraint on `(crew_assignment_id, phase_code)`. Order is determined by `sequence`.

**P2B training and P3 readiness cannot be inferred reliably from legacy `EmployeeDeployment` date fields.** Those legacy records collapse standby/travel/vessel into fixed columns and do not model training or readiness occurrences.

## 6. Allowed transition concept

Structural allowed next phases live in `App\Support\CrewMovements\CrewMovementTransitionMap`.

- Transitions are phase-code to phase-code rules for the movement service (Phase 1+).
- **Plan sign-off** does not leave P4; it is a planning action while still on vessel.
- Invalid transitions (for example P4 → P2A on the same assignment) are rejected by the map.
- P5 → P0 and P6 → P0 represent starting **P0 of a linked new assignment** (transfer / redeploy), not rewriting history on the closed cycle.

## 7. Transfer and redeployment concept

Transfer and redeployment should create a **new** `CrewAssignment` linked via `previous_assignment_id` rather than overwriting historical vessel or phase data on the prior cycle.

Phase 0 only stores the self-link columns and allows P5/P6 → P0 in the transition map vocabulary. Execution is out of scope.

## 8. Legacy `EmployeeDeployment` compatibility

- `EmployeeDeployment` remains the **current compatibility and sea-service record** until later phases introduce dual-write or cutover.
- Optional `employee_deployment_id` on `CrewAssignment` links a cycle to a legacy deployment without changing deployment schema, routes, UI, or sync.
- Existing Crew Deployments controllers, forms, permissions, tests, and sync helpers must keep working unchanged.

## 9. Company-scoping requirements

- Every assignment and phase row has `company_id`.
- Queries and future mutations must scope to the active company.
- `assignment_no` is unique **per company**, not globally.
- Cross-company FK targets must not leak through controllers in later phases.

## 10. Audit requirements

Models use `LogsActivityWithCompany` so Spatie activity rows carry `company_id`. Important assignment and phase attributes are logged dirty-only for later movement corrections and compliance.

## 11. Future payroll, billing, cost, and margin integration

`details` JSON on phases and assignment-level links are reserved for later cost/billing payloads (hotel, training, flights, visa, margin). Phase 0 does **not** calculate payroll, client billing, or invoices.

## 12. Deliberately excluded from Phase 0

- Crew Movement UI / Current Crew board / quick-action dialogs
- Movement action endpoints and full movement service
- Legacy deployment backfill and production data migration
- Dual-write synchronization
- Payroll, billing, hotel/training/flight/visa costs, margin, invoices
- Transfer / redeploy execution and approval workflows
- Removal or modification of existing deployment columns, forms, sea-service sync, or planning sync
