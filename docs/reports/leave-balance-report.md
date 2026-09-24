# Leave Balance Report

Leave Balance Report is a ledger of persisted `LeaveBalance` rows. It is separate from Leave Report, which shows leave requests and approval history.

Opening the report does not call `ensureEmployeeYear()` and does not create or repair missing balances. Current provisioning and sync workflows remain responsible for those rows. If a balance does not exist, the report does not invent entitlement from `LeaveType.days_per_year`.

Authorized users with `reports.leave_balance.update_opening` can record **Previous Used** (opening/current-year migration usage) for the company's current business year. That value is stored independently of OMS-HRM leave requests and is never rebuilt by `leave-balances:sync`.

## Source of truth

One row is one persisted balance for an employee, leave type, and year in the active company.

| Column | Source |
| --- | --- |
| Base Entitlement | `entitled_days` |
| Carried | `carried_days` |
| Total Available | base + carry |
| Previous Used | `opening_used_days` (pre-OMS / opening usage for the year) |
| HRM Used | `used_days` (approved Leave Requests tracked in OMS-HRM) |
| Pending | `pending_days` |
| Remaining | stored generated `remaining_days` = base + carry − previous used − HRM used − pending |

Inactive employees, terminated employees, inactive leave types, and soft-deleted leave types remain visible when a historical balance references them. Those rows are not editable from the report.

## Permissions

- `reports.leave_balance.view` — view the report within the user's employee visibility scope.
- `reports.leave_balance.export` — export that same dataset.
- `reports.leave_balance.update_opening` — record or update previous used days for editable current-year balances the user can see.

These permissions are not copied onto existing roles. Routes enforce them independently of navigation. A user may view and export without being able to edit opening balances.

## Opening balance edits

Editable only when all of the following hold:

- balance `company_id` is the active company
- balance year equals the company business year (`CompanyTimezone`)
- employee is `active` or `on_leave` and visible via `EmployeeVisibilityScope` + `AttendanceLeaveDepartmentScope`
- leave type is active and not soft-deleted

Saving updates only `opening_used_days`, `opening_balance_as_of`, and `opening_balance_note`. Activity log description: `Updated leave opening balance`.

`php artisan leave-balances:sync` (and `LeaveBalanceManager::synchronizeBalanceKey`) recalculates only request-derived `used_days` and `pending_days`. Opening fields are preserved.

## Employee visibility

Rows, filter options, leave-type cards, department tree counts, and export all require `company_id` of the active company, `EmployeeVisibilityScope`, and `AttendanceLeaveDepartmentScope` (`Department.include_in_attendance_leave`). A user restricted to one department cannot discover employees outside that scope, and excluded Attendance/Leave departments never appear.

The department tree only includes departments the user is allowed to access. Unauthorized department names are not shown with zero counts — those nodes are omitted entirely. Allowed child departments may appear as root nodes when their parent department is not visible.

Department tree counts include employees with a persisted leave balance in scope (any status). Soft-deleted balances do not contribute to filter options or department tree counts.

## Filters

| Filter | Behavior |
| --- | --- |
| Year | Defaults to the company business year from `CompanyTimezone`. Options include years that already have visible balances. |
| Employee | Employees with a persisted balance in scope |
| Department | Shared `DepartmentFilterControls` tree (department-only; positions not selectable). Current department of employees with balances |
| Leave type | Filterable cards (All + each type referenced by visible balances, including inactive and soft-deleted types) |
| Category | `LeaveType.category`: annual, sick, or other |
| Employee status | `active`, `inactive`, `on_leave`, `terminated` |
| Search | Employee name or employee number |

## Leave type cards

The top of the page shows filterable leave-type cards. Selecting a card applies `leave_type_id`. There are no Employees / balance-rows / used-days / pending-days metric cards on the page. Summary counts may still be computed for the payload when useful for pagination or tests.

## Table

The **Employee** column shows name, employee number, status, and department together. Export still keeps Employee and Department as separate columns.

When the user can edit opening balances, an Actions column shows **Edit Opening Balance** on editable rows only.

## Export

Excel and CSV use the same filters and visibility as the web report.

Columns: Employee No, Employee, Department, Employee Status, Leave Type, Leave Category, Year, Base Entitlement, Carried Days, Total Available, Previous Used Days, HRM Used Days, Pending Days, Remaining Days.

Filenames: `leave-balance-report-YYYY-MM-DD.xlsx` and `leave-balance-report-YYYY-MM-DD.csv`.
