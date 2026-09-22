# Leave Balance Report

Leave Balance Report is a read-only ledger of persisted `LeaveBalance` rows. It is separate from Leave Report, which shows leave requests and approval history.

Opening the report does not call `ensureEmployeeYear()` and does not create or repair missing balances. Current provisioning and sync workflows remain responsible for those rows. If a balance does not exist, the report does not invent entitlement from `LeaveType.days_per_year`.

## Source of truth

One row is one persisted balance for an employee, leave type, and year in the active company.

| Column | Source |
| --- | --- |
| Base Entitlement | `entitled_days` |
| Carried | `carried_days` |
| Total Available | base + carry |
| Used | `used_days` |
| Pending | `pending_days` |
| Remaining | stored `remaining_days` |

Inactive employees, terminated employees, inactive leave types, and soft-deleted leave types remain visible when a historical balance references them. Those rows are not editable from the report.

## Permissions

- `reports.leave_balance.view` — view the report within the user's employee visibility scope.
- `reports.leave_balance.export` — export that same dataset.

These permissions are not copied onto existing roles. Routes enforce them independently of navigation.

## Employee visibility

Rows, filter options, summary counts, and export all require `company_id` of the active company, `EmployeeVisibilityScope`, and `AttendanceLeaveDepartmentScope` (`Department.include_in_attendance_leave`). A user restricted to one department cannot discover employees outside that scope, and excluded Attendance/Leave departments never appear.

## Filters

| Filter | Behavior |
| --- | --- |
| Year | Defaults to the company business year from `CompanyTimezone`. Options include years that already have visible balances. |
| Employee | Employees with a persisted balance in scope |
| Department | Current department of those employees |
| Leave type | Types referenced by visible balances, including inactive and soft-deleted types |
| Category | `LeaveType.category`: annual, sick, or other |
| Employee status | `active`, `inactive`, `on_leave`, `terminated` |
| Search | Employee name or employee number |

## Summary

Employees, balance rows, used days, and pending days use the same filters and visibility as the table. The report does not present one grand remaining total across unrelated leave types.

## Export

Excel and CSV use the same filters and visibility as the web report.

Columns: Employee No, Employee, Department, Employee Status, Leave Type, Leave Category, Year, Base Entitlement, Carried Days, Total Available, Used Days, Pending Days, Remaining Days.

Filenames: `leave-balance-report-YYYY-MM-DD.xlsx` and `leave-balance-report-YYYY-MM-DD.csv`.
