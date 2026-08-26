# Active employee visibility

Operational and current-workforce workflows operate on **active** employees (`employees.status = 'active'`). Historical, audit, and legal records retain employees regardless of their current status.

This is **employee** status, not login-account status. Whether a `User` may authenticate is `users.status`; see [Global user account status](../permissions.md#global-user-account-status).

There is **no** global Eloquent scope on `Employee`. Inactive and terminated people are not hidden from the database or from history screens.

Canonical filter: `Employee::active()` (`status = 'active'`), always combined with trusted `current_company_id`.

Reusable Support helpers:

- `App\Support\Employees\ActiveEmployeeConstraint` — `apply()` / `whereHas()` for operational queries
- `App\Support\Employees\ActiveCompanyEmployeeRule::exists($companyId)` — FormRequest `employee_id` validation
- `App\Support\Employees\Actions\GuardEmployeeStatusTransition` — blocks `active` → `inactive`/`terminated` while operational work is still open

`EmployeeDirectoryQuery` still defaults the employee directory to active when no status filter is provided. The Filters UI labels that blank option **Active (default)**, never **All**. Passing an explicit status (`active`, `inactive`, `on_leave`, `terminated`) or `status=all` (no HR-status predicate) is the supported way to browse non-default people. Sea service directory is the documented exception that does **not** default to active.

## Operational (active only)

Current workforce pickers, create/update mutations, compliance widgets, and live operational metrics must not include inactive or terminated employees, and must reject cross-company IDs.

| Area | Behaviour |
|------|-----------|
| Employee directory | Defaults to active when status is blank (UI: **Active (default)**, never **All**); `status=all` shows every HR status; explicit `active` / `inactive` / `on_leave` / `terminated` remain |
| Employee pickers (leave, attendance create, crew assignment, planning pool, Hikvision link, user link, announcements, department manager, bulk documents) | Active + current company |
| Documents index folders, compliance table, search, expiry summary, dashboard document health, expiry alerts | Active employees |
| Contracts directory, no-contract list, contract summary | Active employees |
| Bank account directory, operational summary (totals, primary/secondary, Ansari, missing) | Active employees |
| Training directory, compliance summary, dashboard training | Active employees |
| Attendance create picker and **new** attendance records | Active employees |
| Attendance overview **this-month** operational counts; dashboard present/late/absent **today** | Active employees |
| Leave **new** requests | Active employees |
| Leave dashboard on-leave-today / upcoming this week | Active employees |
| Payroll generation / period employee board | Existing `PayrollEmployeeQuery` active + contract rules (unchanged) |
| Current Crew (Draft/Active) | Assignment employee must be active |
| Current Crew Vessel View, Crew Planning Onboard by Vessel, and onboard Excel export | Active employees with current active P4 only |
| Crew Operations dashboard pulse (onboard now, joins/sign-offs, attention) | Active employees |
| Vessel manning actual onboard (active assignment + active P4 + active employee) | Active employees |
| Crew planning pool and current/future Gantt bars | Active employees (vacant slots remain; past bars of inactive people remain) |
| Projected manning Draft/Active and planning-only segments | Active employees |
| Hikvision **new** person-employee link | Active employees |
| Announcement employee audiences | Active employees |
| Imports for contracts, bank accounts, training, crew timesheets | Reject inactive with an explicit error |
| Operational exports | Same query as the parent screen |

## Historical (include inactive / terminated)

Do not delete or hide these when an employee later becomes inactive or terminated.

| Area | Behaviour |
|------|-----------|
| Employee profile / show | Still reachable for authorized HR |
| Per-employee documents, contracts, bank accounts, training, qualifications, vaccinations, languages, work experience | History on the profile |
| Sea service directory, profile, import, and completed P4 sea-service sync | History of all statuses in the company |
| Attendance **record list**, calendar history, YTD/trend event counts | Records remain |
| Updating an existing attendance row | Company-scoped; does not require the employee to still be active |
| Leave request list (including pending that still needs a decision) | Records remain; approvers can still act |
| Finalized payroll records, payslips, WPS | Remain after the employee is inactivated |
| Crew Movement History, completed/cancelled assignments, corrections | Inactive crew remain visible |
| Current Crew when filtered to completed/cancelled, or `include_completed` for those statuses | History remains |
| Projected manning **completed** P4 actual intervals | History remains |
| Crew planning bars whose planned leave is already in the past | Remain on the Gantt as history |
| CV / certificates / salary certificates | May be generated for inactive employees |
| Audit log | Unchanged |
| Dashboard workforce **trends** (hire_date / termination_date running headcount) | Historical by design — not converted to an active-only snapshot |
| Existing Hikvision or user links | Not auto-unlinked; new links still require active |

## Status transitions

Changing **active → inactive** or **active → terminated** (status toggle or profile save) is rejected when any of the following still exist for the current company:

1. Draft or Active `CrewAssignment`
2. Current or future `CrewPlanningAssignment` (`planned_leave_date` is null or ≥ today in company timezone)
3. Pending leave request
4. Employee is a department `manager_id`

Messages are specific to the blocking condition. Records are never cascade-deleted.

`on_leave` is not treated as leaving the operational workforce for this guard.

Hikvision links are **not** cleared automatically on status change.

## Self-service

A linked inactive/terminated employee can still sign in. Personal dashboard sets `is_active_workforce` from `employees.status === 'active'`. Historical payslips, documents, and leave remain; current leave-request actions are hidden when the link is not active. Login is not disabled.

## Tenancy

Every check uses trusted `current_company_id`. Operational `employee_id` validation is:

```php
ActiveCompanyEmployeeRule::exists($companyId);
```

which requires `company_id` + `status = active` (and excludes soft-deleted rows).

## Known domain exceptions

| Exception | Why |
|-----------|-----|
| Employee directory with explicit `status` or `status=all` | HR must be able to find inactive/terminated people, or browse every status |
| Sea service module | Employment/crew **history**, not current roster |
| Payroll `forPeriod` still requires **current** `employees.status = active` plus contracts overlapping the period start | Existing payroll generation rule; do not treat this as “as-of status” |
| Leave pending / awaiting-approval counts | In-progress workflow must remain actionable after inactivation is blocked going forward; existing pending rows stay visible |
| Attendance **update** of an existing row | Historical correction, company-scoped only |
| User update keeping an **already linked** inactive employee | Preserve the login link; **new** links still require active |
| Hikvision access events for previously linked people | Event history |

## Implementation notes

- Prefer `Employee::active()` or `ActiveEmployeeConstraint` over repeating `where('status', 'active')`.
- Do not add a global Employee scope.
- Do not use frontend hiding as the security boundary.
- CrewAssignment remains the source of truth for P0–P6. This policy does not change phase semantics or reintroduce EmployeeDeployment.
