# Crew Relief Report

The **Crew Relief Report** is a focused operational report designed to help the Crewing team answer one core question quickly:

> **Who is signing off soon, and is their replacement/relief ready?**

It displays active onboard crew approaching sign-off, checks whether their replacement has been explicitly assigned, evaluates whether that relief is operationally ready, checks for scheduling and assignment conflicts, and highlights what needs immediate attention.

---

## 1. Source of Truth & Row Eligibility

- **Current Assignment Scope:** Primarily shows crew who are currently in **P4 — On Vessel** (`status = active`, with active P4 `CrewAssignmentPhase`).
- **Active Employee Constraint:** Applies `ActiveEmployeeConstraint` and `CurrentOnboardCrewQuery::applyConstraint()`. Inactive and terminated employees are excluded from operational lists.
- **Tenancy:** Strictly scoped by the current active company (`current_company_id`). Cross-company assignments are never exposed or linked.
- **Actual Joined Date:** Evaluated strictly from the actual P4 start timestamp (`actual_start_at`). Planned join dates are never treated as actual joining.
- **Days Onboard:** Calculated from the actual P4 start date through today in the company timezone via `CrewTourOfDutyCalculator`.
- **Planned Sign-Off:** Authoritative planned sign-off date from the current `CrewAssignment` (`planned_signoff_at`).

---

## 2. Explicit Relief Relationship

The report inspects explicit replacements only. **It does not infer relief simply because another future assignment shares the same vessel, client, or rank.**

- **Linkage Source:** Uses `relieves_crew_assignment_id` on incoming `CrewAssignment` or `CrewPlanningAssignment`.
- **Precedence:** Managed by `CrewReliefPlanningLoader`:
  1. Named incoming `CrewAssignment` in `Planned` or `Active` status that targets the outgoing assignment via `relieves_crew_assignment_id`.
  2. Fallback to `CrewPlanningAssignment` when no active assignment exists.
- **Tenancy Enforcement:** Relief assignments must belong to the same company. Foreign company relief assignments are rejected and ignored.

---

## 3. Operational Readiness

The report resolves an operational readiness status for each row:

| Readiness | Domain Condition |
|---|---|
| **Ready** | Explicit relief is assigned and currently in **P3 Ready to Join** with no conflict and acceptable joining date. |
| **In Progress** | Explicit relief is assigned and currently progressing through preparation phases: **P0 Pre-Mobilisation**, **P1 Travel In**, **P2A Join Standby**, or **P2B Training**. |
| **Not Assigned** | No explicit relief assignment is linked to the outgoing assignment. |
| **At Risk** | Relief has an assignment conflict/overlap, or relief planned join date is after the outgoing crew member's planned sign-off date. |
| **Joined** | Relief crew has arrived and entered **P4 On Vessel**. |

---

## 4. Attention Rules

Attention rules are centralized in `CrewReliefAttentionResolver` and determine backend urgency ranking (1 to 6) and sorting:

### Critical (Red)
1. **Sign-off overdue:** Planned sign-off date has passed while the crew member remains onboard (e.g. `Sign-off overdue by 4 days`).
2. **Relief assignment conflict:** The assigned relief employee has an overlapping active or planned assignment on another vessel.
3. **No relief assigned (Urgent):** Sign-off is approaching within 7 days and no relief has been assigned.

### Warning (Amber)
1. **Relief joins late:** Relief's planned join date occurs after the outgoing crew member's planned sign-off date (e.g. `Relief joins 3 days late`).
2. **Relief not ready:** Sign-off is approaching within 14 days but relief is still in training or standby (e.g. `Relief still in training`).
3. **No relief assigned:** Sign-off is more than 7 days away but no relief has been assigned.
4. **Sign-off approaching:** Sign-off is within 7 days, even if relief is ready or joined.

### Healthy (Green)
1. **Relief ready:** Relief is linked, in P3 Ready to Join, with no conflicts and acceptable timing.
2. **Relief joined:** Relief is already in P4 On Vessel.

---

## 5. Tenancy & Employee Visibility

- **Tenant Isolation:** All queries, summary counts, filter options, and exports enforce `company_id = current_company_id`.
- **Department/Role Visibility:**
  - `EmployeeVisibilityScope` is applied to row assignments and search queries.
  - When an authorized user is restricted to specific departments and an outgoing crew member's relief belongs to an inaccessible department, the relief employee's name is redacted to prevent unauthorized disclosure.
  - Summary counts reflect only the records accessible to the authenticated user.

---

## 6. Filters & Quick Presets

- **Server-Side Filters:** Search (crew, vessel, rank, assignment number), Vessel, Client, Rank, Planned Sign-off Date Range (From/To), Readiness, and Attention.
- **Quick Presets:**
  - `Next 7 Days`: Sign-offs due within the next 7 days (plus all overdue).
  - `Next 14 Days`: Sign-offs due within the next 14 days (plus all overdue).
  - `Next 30 Days`: Sign-offs due within the next 30 days (plus all overdue).
  - `No Relief`: Assignments with approaching sign-off and no relief assigned.
  - `Not Ready`: Assignments where relief is still in preparation or at risk.
  - `Overdue`: All onboard assignments whose planned sign-off date has passed.

---

## 7. Export & Permissions

- **Permissions:**
  - `reports.crew_relief.view`: Access the report page and perform searches/filtering.
  - `reports.crew_relief.export`: Download XLSX/CSV reports.
- **Export Consistency:** `CrewReliefExport` executes the identical backend query and presenter logic, preserving active filters, tenant scoping, and employee visibility redactions.
- **Navigation:** Accessible under **Crew Operations → Relief Report** (`/organization/reports/crew-relief`).
